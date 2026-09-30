<?php

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\ContentIdea;
use App\Models\ContentTemplate;
use App\Models\CreatorAsset;
use App\Models\CreatorProject;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceBrandProfile;
use App\Models\WorkspaceMembership;
use App\Services\Creator\CreatorDocument;
use App\Services\Posts\PostReviewService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    [$this->actor, $this->workspace] = ownerActingIn();
});

function brandPayload(array $overrides = []): array
{
    return [...['expected_workspace_id' => test()->workspace->id, 'expected_revision' => 0, 'tagline' => 'Made for people', 'voice' => 'Warm and concise', 'audience' => 'Local business owners', 'guidelines' => 'Never invent customer quotes', 'palette' => ['#123456'], 'logo_asset_id' => null, 'default_hashtags' => ['#OurBrand'], 'first_comment_enabled' => false, 'first_comment' => null], ...$overrides];
}

function contentTemplatePayload(array $overrides = []): array
{
    return [...['expected_workspace_id' => test()->workspace->id, 'name' => 'Product launch', 'brief' => 'Show the new collection', 'caption' => 'Our new collection is here.', 'hashtags' => ['#NewCollection'], 'first_comment' => 'See the full collection'], ...$overrides];
}

function contentIdeaPayload(array $overrides = []): array
{
    return [...['expected_workspace_id' => test()->workspace->id, 'title' => 'Launch carousel', 'brief' => 'Internal direction: do not publish this', 'caption' => 'Meet the collection.', 'tags' => ['launch', 'summer'], 'category' => 'Campaigns', 'status' => 'planned', 'due_on' => '2026-10-15', 'template_id' => null], ...$overrides];
}

it('provides workspace-specific native pages and private brand context', function () {
    foreach (['brand', 'templates', 'ideas'] as $tab) {
        $this->get(route('content.'.$tab.'.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('content/index')->where('tab', $tab)->where('workspaceId', $this->workspace->id));
    }
    $this->getJson(route('content.context'))->assertOk()->assertJsonPath('brand.revision', 0)->assertJsonCount(0, 'templates');
});

it('saves brand preferences with optimistic concurrency and same-workspace logos', function () {
    $logo = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id, 'kind' => 'logo']);
    $this->putJson(route('content.brand.update'), brandPayload(['logo_asset_id' => $logo->id]))->assertOk()->assertJsonPath('brand.revision', 1)->assertJsonPath('brand.logo.id', $logo->id);
    $this->putJson(route('content.brand.update'), brandPayload())->assertConflict();
    $this->putJson(route('content.brand.update'), brandPayload(['expected_revision' => 1, 'voice' => 'Playful']))->assertOk()->assertJsonPath('brand.revision', 2);
    expect(WorkspaceBrandProfile::query()->count())->toBe(1);
});

it('rejects foreign or non-logo brand assets and malformed preferences', function () {
    $foreign = CreatorAsset::factory()->create(['kind' => 'logo']);
    $image = CreatorAsset::factory()->create(['workspace_id' => $this->workspace->id, 'kind' => 'image']);
    foreach ([$foreign->id, $image->id] as $id) {
        $this->putJson(route('content.brand.update'), brandPayload(['logo_asset_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('logo_asset_id');
    }
    $this->putJson(route('content.brand.update'), brandPayload(['palette' => ['red'], 'default_hashtags' => ['not a hashtag']]))->assertUnprocessable()->assertJsonValidationErrors(['palette.0', 'default_hashtags.0']);
});

it('allows members to capture ideas but reserves brand changes for administrators', function () {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    $this->actingAs($member);
    $this->putJson(route('content.brand.update'), brandPayload())->assertForbidden();
    $this->postJson(route('content.ideas.store'), contentIdeaPayload())->assertCreated();
    $this->postJson(route('content.templates.store'), contentTemplatePayload())->assertCreated();
});

it('snapshots a saved design and instantiates an independent versioned project', function () {
    $project = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id]);
    $original = $project->document;
    $response = $this->postJson(route('content.templates.store'), contentTemplatePayload(['source_project_id' => $project->id, 'source_project_revision' => 1]))->assertCreated()->assertJsonPath('template.has_design', true);
    $id = $response->json('template.id');
    $changed = $original;
    $changed['slides'][0]['background_color'] = '#000000';
    $project->forceFill(['document' => $changed, 'document_hash' => CreatorDocument::hash($changed), 'revision' => 2])->save();
    $copy = $this->postJson(route('content.templates.instantiate', $id), ['expected_revision' => 1])->assertCreated()->assertJsonPath('project.revision', 1)->assertJsonPath('project.document', $original);
    expect($copy->json('project.id'))->not->toBe($project->id);
    expect(ContentTemplate::query()->findOrFail($id)->document)->toBe($original);
});

it('rejects stale design snapshots and cross-workspace source projects', function () {
    $project = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id, 'revision' => 2]);
    $this->postJson(route('content.templates.store'), contentTemplatePayload(['source_project_id' => $project->id, 'source_project_revision' => 1]))->assertConflict();
    $foreign = CreatorProject::factory()->create();
    $this->postJson(route('content.templates.store'), contentTemplatePayload(['source_project_id' => $foreign->id, 'source_project_revision' => 1]))->assertUnprocessable();
});

it('isolates foreign templates ideas and prompts even when IDs are known', function () {
    $template = ContentTemplate::factory()->create();
    $idea = ContentIdea::factory()->create();
    $this->putJson(route('content.templates.update', $template), contentTemplatePayload(['expected_revision' => 1]))->assertNotFound();
    $this->postJson(route('content.templates.instantiate', $template), ['expected_revision' => 1])->assertNotFound();
    $this->putJson(route('content.ideas.update', $idea), contentIdeaPayload(['expected_revision' => 1]))->assertNotFound();
    $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertNotFound();
    $this->postJson(route('content.prompt'), ['expected_workspace_id' => $this->workspace->id, 'brief' => 'A launch', 'template_id' => $template->id])->assertUnprocessable();
    $this->postJson(route('content.ideas.store'), contentIdeaPayload(['template_id' => $template->id]))->assertUnprocessable();
    $this->getJson(route('content.context'))->assertJsonCount(0, 'templates');
});

it('prepares editable brand prompts locally without making provider calls', function () {
    $this->putJson(route('content.brand.update'), brandPayload())->assertOk();
    $template = ContentTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'brief' => 'Use clean editorial imagery']);
    $response = $this->postJson(route('content.prompt'), ['expected_workspace_id' => $this->workspace->id, 'brief' => 'Introduce our studio', 'template_id' => $template->id])->assertOk();
    expect($response->json('prompt'))->toContain('Warm and concise', '#123456', 'Introduce our studio', 'Use clean editorial imagery', '#OurBrand');
    Http::assertNothingSent();
});

it('converts an idea exactly once without publishing internal direction or selecting accounts', function () {
    ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->putJson(route('content.brand.update'), brandPayload())->assertOk();
    $idea = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, ...Arr::except(contentIdeaPayload(), ['expected_workspace_id'])]);
    $first = $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertOk();
    $second = $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertOk();
    expect($second->json('post_id'))->toBe($first->json('post_id'));
    $post = Post::query()->findOrFail($first->json('post_id'));
    expect($post->status)->toBe(PostStatus::Draft)->and($post->scheduled_at)->toBeNull()->and($post->targets()->count())->toBe(0)
        ->and($post->base_text)->toBe("Meet the collection.\n\n#OurBrand")
        ->and((bool) $post->getAttribute('review_required'))->toBeTrue()
        ->and(app(PostReviewService::class)->canPublish($post))->toBeFalse()
        ->and(Post::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('copies template design into the idea draft workflow while leaving the snapshot immutable', function () {
    $project = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id]);
    $template = ContentTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'document' => $project->document, 'caption' => 'Template caption']);
    $idea = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'template_id' => $template->id]);
    $response = $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertOk();
    $copyId = $response->json('idea.creator_project_id');
    expect($copyId)->not->toBeNull()->not->toBe($project->id);
    expect(CreatorProject::query()->findOrFail($copyId)->document)->toBe($template->document);
    expect(Post::query()->findOrFail($response->json('post_id'))->base_text)->toBe('Template caption');
});

it('blocks stale idea edits conversion and edits after conversion', function () {
    $idea = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'revision' => 2]);
    $this->putJson(route('content.ideas.update', $idea), contentIdeaPayload(['expected_revision' => 1]))->assertConflict();
    $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertConflict();
    $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 2])->assertOk();
    $this->putJson(route('content.ideas.update', $idea), contentIdeaPayload(['expected_revision' => 3]))->assertUnprocessable();
});

it('archives templates without deleting history and rejects archived template conversions', function () {
    $template = ContentTemplate::factory()->create(['workspace_id' => $this->workspace->id]);
    $idea = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'template_id' => $template->id]);
    $this->putJson(route('content.templates.update', $template), contentTemplatePayload(['expected_revision' => 1, 'archived' => true]))->assertOk();
    $this->postJson(route('content.templates.instantiate', $template), ['expected_revision' => 2])->assertUnprocessable();
    $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertUnprocessable();
    expect(Post::query()->count())->toBe(0);
    $this->getJson(route('content.context'))->assertJsonCount(0, 'templates');
    $this->putJson(route('content.templates.update', $template), contentTemplatePayload(['expected_revision' => 2, 'archived' => false]))->assertOk();
    $this->getJson(route('content.context'))->assertJsonCount(1, 'templates');
});

it('requires authentication on all content endpoints', function () {
    auth()->logout();
    $this->getJson(route('content.context'))->assertUnauthorized();
    $this->postJson(route('content.prompt'), ['brief' => 'test'])->assertUnauthorized();
});

it('revalidates same-workspace asset references before instantiating a stored snapshot', function () {
    $project = CreatorProject::factory()->create(['workspace_id' => $this->workspace->id]);
    $asset = CreatorAsset::factory()->create();
    $document = $project->document;
    $document['slides'][0]['layers'] = [['id' => 'foreign-image', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'asset_id' => $asset->id, 'fit' => 'contain']];
    $template = ContentTemplate::factory()->create(['workspace_id' => $this->workspace->id, 'document' => $document]);
    $this->postJson(route('content.templates.instantiate', $template), ['expected_revision' => 1])->assertUnprocessable();
    $idea = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'template_id' => $template->id]);
    $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertUnprocessable();
    expect(Post::query()->count())->toBe(0)->and($idea->fresh()->draft_post_id)->toBeNull();
});

it('prevents edits and reads when the user no longer belongs to the current workspace', function () {
    $outsider = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->actingAs($outsider);
    $this->getJson(route('content.context'))->assertForbidden();
    $this->postJson(route('content.ideas.store'), contentIdeaPayload())->assertForbidden();
    $this->postJson(route('content.templates.store'), contentTemplatePayload())->assertForbidden();
    $this->putJson(route('content.brand.update'), brandPayload())->assertForbidden();
});

it('keeps drafts empty when the idea contains only internal directions', function () {
    $idea = ContentIdea::factory()->create(['workspace_id' => $this->workspace->id, 'brief' => 'Confidential internal planning notes', 'caption' => null]);
    $result = $this->postJson(route('content.ideas.convert', $idea), ['expected_revision' => 1])->assertOk();
    expect(Post::query()->findOrFail($result->json('post_id'))->base_text)->toBe('');
});

it('rejects oversized combined prompts without making a paid request', function () {
    WorkspaceBrandProfile::factory()->create(['workspace_id' => $this->workspace->id, 'guidelines' => str_repeat('A', 9990)]);
    $this->postJson(route('content.prompt'), ['expected_workspace_id' => $this->workspace->id, 'brief' => 'Create a launch image'])->assertUnprocessable()->assertJsonValidationErrors('brief');
    Http::assertNothingSent();
});

it('rejects a stale company tab after the active workspace changes', function () {
    $second = Workspace::factory()->create(['owner_id' => $this->actor->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $second->id, 'user_id' => $this->actor->id, 'role' => WorkspaceRole::Owner]);
    $this->actor->forceFill(['current_workspace_id' => $second->id])->save();
    $this->putJson(route('content.brand.update'), brandPayload())->assertUnprocessable()->assertJsonValidationErrors('expected_workspace_id');
    $this->postJson(route('content.templates.store'), contentTemplatePayload())->assertUnprocessable()->assertJsonValidationErrors('expected_workspace_id');
    $this->postJson(route('content.ideas.store'), contentIdeaPayload())->assertUnprocessable()->assertJsonValidationErrors('expected_workspace_id');
    $this->postJson(route('content.prompt'), ['expected_workspace_id' => $this->workspace->id, 'brief' => 'Old company content'])->assertUnprocessable()->assertJsonValidationErrors('expected_workspace_id');
    expect(WorkspaceBrandProfile::withoutGlobalScopes()->count())->toBe(0)
        ->and(ContentIdea::withoutGlobalScopes()->count())->toBe(0)
        ->and(ContentTemplate::withoutGlobalScopes()->count())->toBe(0);
});
