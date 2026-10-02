<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\PublishPostTool;
use App\Mcp\Tools\QueuePostTool;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Queue;

test('web post actions cannot replace a terminal or publishing state', function (string $action, PostStatus $status) {
    Queue::fake();
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $post = Post::factory()->for($workspace)->create(['status' => $status]);
    PostTarget::factory()->for($post)->published()->create();

    $this->actingAs($user);
    $payload = ['scheduled_at' => now()->addDay()->toIso8601String()];
    $response = $action === 'schedule'
        ? $this->putJson("/posts/{$post->id}/schedule", $payload)
        : $this->postJson("/posts/{$post->id}/{$action}", $payload);

    $response->assertStatus($status === PostStatus::Deleted ? 404 : 409);
    expect($post->fresh()->status)->toBe($status);
    Queue::assertNothingPushed();
})->with(['schedule', 'queue', 'publish'])->with([
    PostStatus::Published, PostStatus::Publishing, PostStatus::Partial, PostStatus::Failed, PostStatus::Deleted,
]);

test('API post actions cannot replace a terminal or publishing state', function (string $action, PostStatus $status) {
    Queue::fake();
    [, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['status' => $status]);
    PostTarget::factory()->for($post)->published()->create();

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/{$action}", [
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertConflict();

    expect($post->fresh()->status)->toBe($status);
    Queue::assertNothingPushed();
})->with(['schedule', 'queue', 'publish'])->with([
    PostStatus::Published, PostStatus::Publishing, PostStatus::Partial, PostStatus::Failed, PostStatus::Deleted,
]);

test('MCP post actions cannot replace a terminal or publishing state', function (string $tool, PostStatus $status) {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create(['status' => $status]);
    PostTarget::factory()->for($post)->published()->create();

    ShoutrrrServer::actingAs($user)->tool($tool, [
        'post_id' => $post->id,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'confirm' => true,
    ])->assertHasErrors();

    expect($post->fresh()->status)->toBe($status);
    Queue::assertNothingPushed();
})->with([SchedulePostTool::class, QueuePostTool::class, PublishPostTool::class])->with([
    PostStatus::Published, PostStatus::Publishing, PostStatus::Partial, PostStatus::Failed, PostStatus::Deleted,
]);

test('API publishing rejects a post without targets without changing state', function () {
    Queue::fake();
    [, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create();

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/publish")->assertUnprocessable();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNothingPushed();
});

test('MCP publishing rejects a post without targets without changing state', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create();

    ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, ['post_id' => $post->id, 'confirm' => true])
        ->assertHasErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNothingPushed();
});

test('unsubscribed API workspaces cannot start publication', function (string $action) {
    Queue::fake();
    Workspace::factory()->create();
    [, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create();
    PostTarget::factory()->for($post)->create();
    config(['subscriptions.enabled' => true]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/{$action}", [
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertPaymentRequired();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNothingPushed();
})->with(['schedule', 'queue', 'publish']);

test('unsubscribed MCP workspaces cannot start publication', function (string $tool) {
    Queue::fake();
    Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create();
    PostTarget::factory()->for($post)->create();
    config(['subscriptions.enabled' => true]);

    ShoutrrrServer::actingAs($user)->tool($tool, [
        'post_id' => $post->id,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'confirm' => true,
    ])->assertHasErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNothingPushed();
})->with([SchedulePostTool::class, QueuePostTool::class, PublishPostTool::class]);

test('an unsubscribed workspace can cancel its scheduled API post', function () {
    Workspace::factory()->create();
    [, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['status' => PostStatus::Scheduled]);
    config(['subscriptions.enabled' => true]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/schedule", ['scheduled_at' => null])
        ->assertOk()->assertJsonPath('post.status', 'draft');
});

test('a deleted API post cannot be resurrected by retrying a failed target', function () {
    Queue::fake();
    [, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['status' => PostStatus::Deleted]);
    $target = PostTarget::factory()->for($post)->failed()->create();

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/targets/{$target->id}/retry")->assertConflict();

    expect($post->fresh()->status)->toBe(PostStatus::Deleted);
    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Queue::assertNothingPushed();
});

test('a deleted MCP post cannot be resurrected by retrying a failed target', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create(['status' => PostStatus::Deleted]);
    $target = PostTarget::factory()->for($post)->failed()->create();

    ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id, 'target_id' => $target->id, 'confirm' => true,
    ])->assertHasErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Deleted);
    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Queue::assertNothingPushed();
});
