<?php

declare(strict_types=1);

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\SaveBrandProfileRequest;
use App\Http\Requests\Content\SaveContentIdeaRequest;
use App\Http\Requests\Content\SaveContentTemplateRequest;
use App\Models\ContentIdea;
use App\Models\ContentTemplate;
use App\Models\CreatorAsset;
use App\Models\CreatorProject;
use App\Models\Post;
use App\Models\Workspace;
use App\Models\WorkspaceBrandProfile;
use App\Services\Content\BrandPromptBuilder;
use App\Services\Content\ContentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContentWorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $tab = match (true) {
            $request->routeIs('content.templates.index') => 'templates', $request->routeIs('content.ideas.index') => 'ideas', default => 'brand',
        };
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'status' => ['nullable', Rule::in(['all', 'inbox', 'planned', 'drafted', 'archived'])], 'archived' => ['nullable', 'boolean']]);
        $query = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? 'all';

        return Inertia::render('content/index', [
            'tab' => $tab,
            'workspaceId' => $workspaceId,
            'workspaceName' => Workspace::query()->findOrFail($workspaceId)->name,
            'canManageBrand' => $request->user()->hasAllPermissions(['workspace.settings.manage'], $workspaceId),
            'brand' => $this->brand($workspaceId),
            'logos' => CreatorAsset::query()->where('workspace_id', $workspaceId)->where('kind', 'logo')->latest()->limit(100)->get()->map(fn (CreatorAsset $asset): array => $asset->toView())->all(),
            'projects' => CreatorProject::query()->where('workspace_id', $workspaceId)->latest('updated_at')->limit(100)->get(['id', 'name', 'revision'])->toArray(),
            'templateOptions' => ContentTemplate::query()->where('workspace_id', $workspaceId)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->toArray(),
            'templates' => ContentTemplate::query()->where('workspace_id', $workspaceId)
                ->when(! ($filters['archived'] ?? false), fn ($builder) => $builder->whereNull('archived_at'))
                ->when($tab === 'templates' && $query !== '', fn ($builder) => $builder->whereLike('name', '%'.$query.'%'))
                ->latest('updated_at')->paginate(24, ['*'], 'templates_page')->withQueryString()->through(fn (ContentTemplate $template): array => $this->template($template)),
            'ideas' => ContentIdea::query()->where('workspace_id', $workspaceId)
                ->when($tab === 'ideas' && $status !== 'all', fn ($builder) => $builder->where('status', $status))
                ->when($tab === 'ideas' && $query !== '', fn ($builder) => $builder->where(function ($builder) use ($query): void {
                    $builder->whereLike('title', '%'.$query.'%')->orWhereLike('category', '%'.$query.'%')->orWhereLike('brief', '%'.$query.'%');
                }))
                ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')->orderBy('due_on')->latest('updated_at')
                ->paginate(24, ['*'], 'ideas_page')->withQueryString()->through(fn (ContentIdea $idea): array => $this->idea($idea)),
            'filters' => ['q' => $query, 'status' => $status, 'archived' => (bool) ($filters['archived'] ?? false)],
        ]);
    }

    public function context(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $templates = ContentTemplate::query()->where('workspace_id', $workspaceId)->whereNull('archived_at')->orderBy('name')->get();

        return response()->json(['workspace_id' => $workspaceId, 'brand' => $this->brand($workspaceId), 'templates' => $templates->map(fn (ContentTemplate $template): array => $this->template($template))->all()]);
    }

    public function updateBrand(SaveBrandProfileRequest $request, ContentWorkflowService $workflows): JsonResponse|RedirectResponse
    {
        $brand = $workflows->saveBrand($request->user(), $request->validated());

        return $request->expectsJson() ? response()->json(['brand' => $this->brand($brand->workspace_id)]) : back()->with('success', 'Brand profile saved.');
    }

    public function storeTemplate(SaveContentTemplateRequest $request, ContentWorkflowService $workflows): JsonResponse|RedirectResponse
    {
        $template = $workflows->saveTemplate($request->user(), $request->validated());

        return $request->expectsJson() ? response()->json(['template' => $this->template($template)], 201) : back()->with('success', 'Reusable template saved.');
    }

    public function updateTemplate(SaveContentTemplateRequest $request, ContentTemplate $contentTemplate, ContentWorkflowService $workflows): JsonResponse|RedirectResponse
    {
        $template = $workflows->saveTemplate($request->user(), $request->validated(), $contentTemplate);

        return $request->expectsJson() ? response()->json(['template' => $this->template($template)]) : back()->with('success', 'Template updated.');
    }

    public function instantiateTemplate(Request $request, ContentTemplate $contentTemplate, ContentWorkflowService $workflows): JsonResponse
    {
        $this->workspaceId($request);
        abort_unless($request->user()->can('create', Post::class), 403);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        $project = $workflows->instantiate($request->user(), $contentTemplate, (int) $data['expected_revision']);

        return response()->json(['project' => $project->toView()], 201);
    }

    public function storeIdea(SaveContentIdeaRequest $request, ContentWorkflowService $workflows): JsonResponse|RedirectResponse
    {
        $idea = $workflows->saveIdea($request->user(), $request->validated());

        return $request->expectsJson() ? response()->json(['idea' => $this->idea($idea)], 201) : back()->with('success', 'Idea captured.');
    }

    public function updateIdea(SaveContentIdeaRequest $request, ContentIdea $contentIdea, ContentWorkflowService $workflows): JsonResponse|RedirectResponse
    {
        $idea = $workflows->saveIdea($request->user(), $request->validated(), $contentIdea);

        return $request->expectsJson() ? response()->json(['idea' => $this->idea($idea)]) : back()->with('success', 'Idea updated.');
    }

    public function convertIdea(Request $request, ContentIdea $contentIdea, ContentWorkflowService $workflows): JsonResponse|RedirectResponse
    {
        $this->workspaceId($request);
        abort_unless($request->user()->can('create', Post::class), 403);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        $post = $workflows->convert($request->user(), $contentIdea, (int) $data['expected_revision']);

        return $request->expectsJson() ? response()->json(['post_id' => $post->id, 'post_url' => route('posts.show', $post), 'idea' => $this->idea($contentIdea->fresh())]) : redirect()->route('posts.show', $post)->with('success', 'Draft created. Choose destinations, finish the content and submit it for review.');
    }

    public function prompt(Request $request, BrandPromptBuilder $prompts): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $data = $request->validate(['expected_workspace_id' => ['required', 'uuid', Rule::in([$workspaceId])], 'brief' => ['required', 'string', 'max:10000'], 'template_id' => ['nullable', 'uuid', Rule::exists('content_templates', 'id')->where('workspace_id', $workspaceId)->whereNull('archived_at')]]);
        $template = isset($data['template_id']) ? ContentTemplate::query()->where('workspace_id', $workspaceId)->findOrFail($data['template_id']) : null;

        return response()->json(['prompt' => $prompts->build($workspaceId, $data['brief'], $template)]);
    }

    private function workspaceId(Request $request): string
    {
        $workspaceId = (string) $request->user()->current_workspace_id;
        abort_unless($workspaceId !== '' && $request->user()->hasAllPermissions(['workspace.read'], $workspaceId), 403);

        return $workspaceId;
    }

    /** @return array<string, mixed> */
    private function brand(string $workspaceId): array
    {
        $profile = WorkspaceBrandProfile::query()->where('workspace_id', $workspaceId)->first();
        if (! $profile) {
            return ['revision' => 0, 'tagline' => '', 'voice' => '', 'audience' => '', 'guidelines' => '', 'palette' => [], 'logo_asset_id' => null, 'default_hashtags' => [], 'first_comment_enabled' => false, 'first_comment' => '', 'logo' => null];
        }
        $logo = $profile->logo_asset_id ? CreatorAsset::query()->where('workspace_id', $workspaceId)->find($profile->logo_asset_id) : null;

        return [...$profile->only(['revision', 'tagline', 'voice', 'audience', 'guidelines', 'palette', 'logo_asset_id', 'default_hashtags', 'first_comment_enabled', 'first_comment']), 'logo' => $logo?->toView()];
    }

    /** @return array<string, mixed> */
    private function template(ContentTemplate $template): array
    {
        return [...$template->only(['id', 'name', 'description', 'brief', 'caption', 'hashtags', 'first_comment', 'revision', 'source_project_id', 'source_project_revision', 'archived_at']), 'has_design' => $template->document !== null];
    }

    /** @return array<string, mixed> */
    private function idea(ContentIdea $idea): array
    {
        return [...$idea->only(['id', 'title', 'brief', 'caption', 'category', 'tags', 'status', 'template_id', 'draft_post_id', 'creator_project_id', 'revision']), 'due_on' => $idea->due_on?->format('Y-m-d'), 'post_url' => $idea->draft_post_id ? route('posts.show', $idea->draft_post_id) : null];
    }
}
