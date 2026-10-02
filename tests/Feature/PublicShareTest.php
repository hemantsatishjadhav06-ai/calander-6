<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostShare;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Storage;

function shareFor(string $token, ?callable $state = null): PostShare
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id, 'base_text' => 'shared body',
    ]);
    $factory = PostShare::factory()->for($post)->state(['token_hash' => hash('sha256', $token)]);
    if ($state !== null) {
        $factory = $state($factory);
    }

    return $factory->create();
}

it('renders a read-only view for a valid token', function (): void {
    shareFor('good-token');

    $this->get('/share/good-token')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn ($page) => $page
            ->component('share/show')
            ->where('post.base_text', 'shared body'));
});

it('shows not-available for unknown / revoked / expired tokens', function (): void {
    $this->get('/share/nope')->assertInertia(fn ($page) => $page
        ->component('share/show')->where('post', null));

    shareFor('revoked-token', fn ($f) => $f->revoked());
    $this->get('/share/revoked-token')->assertInertia(fn ($page) => $page->where('post', null));

    shareFor('expired-token', fn ($f) => $f->expired());
    $this->get('/share/expired-token')->assertInertia(fn ($page) => $page->where('post', null));
});

it('stops exposing a shared post after it is retained for remote deletion', function (): void {
    $share = shareFor('deleted-token');
    $share->post->forceFill(['status' => PostStatus::Deleted, 'deleted_at' => now()])->save();

    $this->get('/share/deleted-token')->assertInertia(fn ($page) => $page->where('post', null));
});

it('shows the shared media and accounts to a member of a different workspace', function (): void {
    Storage::fake('public');
    $share = shareFor('cross-workspace-token');
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $share->post->workspace_id,
        'handle' => 'shared-account',
    ]);
    PostTarget::factory()->for($share->post)->create(['connected_account_id' => $account->id]);
    $media = PostMedia::factory()->for($share->post)->create([
        'workspace_id' => $share->post->workspace_id,
        'disk' => 'public',
    ]);
    $otherWorkspace = Workspace::factory()->create();
    $viewer = User::factory()->create(['current_workspace_id' => $otherWorkspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $otherWorkspace->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->get('/share/cross-workspace-token')
        ->assertInertia(fn ($page) => $page
            ->where('post.targets.0.handle', 'shared-account')
            ->where('post.media.0.id', $media->id));
});
