<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PublishPostTarget;
use App\Jobs\RepostPostTarget;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\PublishPostTool;
use App\Mcp\Tools\QueuePostTool;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\PostApprovalService;
use App\Services\Publishing\TokenManager;
use App\Services\Repost\RepostConnectorRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function approvalEntryPointSchedule(Workspace $workspace): void
{
    $schedule = PostingSchedule::factory()->for($workspace)->create(['timezone' => 'UTC']);
    PostingScheduleSlot::factory()->for($schedule)->create(['weekday' => 1, 'hour' => 9]);
}

function approveEntryPointPost(Post $post, User $owner, ?CarbonInterface $plannedAt = null): void
{
    Context::add('workspace_id', $post->workspace_id);
    $approvals = app(PostApprovalService::class);
    $approvals->review($post, $owner, 'plan', $approvals->revision($post), plannedAt: $plannedAt);
    $revision = $approvals->revision($post);
    $approvals->review($post, $owner, 'request', $revision);
    $approvals->review($post, $owner, 'approve', $revision);
}

test('web publication entry points reject unapproved drafts without changing state', function (string $action): void {
    [$user, $workspace] = ownerActingIn();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    approvalEntryPointSchedule($workspace);
    $this->travelTo(CarbonImmutable::parse('2030-01-07T08:30:00Z'));
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $target = PostTarget::factory()->for($post)->create([
        'status' => $action === 'retry' ? PostTargetStatus::Failed : PostTargetStatus::Pending,
    ]);
    Bus::fake();

    $response = match ($action) {
        'schedule' => $this->putJson("/posts/{$post->id}/schedule", ['scheduled_at' => now()->addDay()->toIso8601String()]),
        'retry' => $this->postJson("/posts/{$post->id}/targets/{$target->id}/retry"),
        default => $this->postJson("/posts/{$post->id}/{$action}"),
    };

    $response->assertUnprocessable()->assertJsonValidationErrors('approval');
    expect($post->refresh()->status)->toBe(PostStatus::Draft)
        ->and($post->scheduled_at)->toBeNull()
        ->and($target->refresh()->status)->toBe($action === 'retry' ? PostTargetStatus::Failed : PostTargetStatus::Pending);
    Bus::assertNotDispatched(PublishPostTarget::class);
})->with(['publish', 'schedule', 'queue', 'retry']);

test('API publication entry points reject unapproved drafts without changing state', function (string $action): void {
    [$user, $workspace, $token] = issuedKey();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    approvalEntryPointSchedule($workspace);
    $this->travelTo(CarbonImmutable::parse('2030-01-07T08:30:00Z'));
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $target = PostTarget::factory()->for($post)->create([
        'status' => $action === 'retry' ? PostTargetStatus::Failed : PostTargetStatus::Pending,
    ]);
    Bus::fake();

    $url = $action === 'retry'
        ? "/api/v1/posts/{$post->id}/targets/{$target->id}/retry"
        : "/api/v1/posts/{$post->id}/{$action}";
    $payload = $action === 'schedule' ? ['scheduled_at' => now()->addDay()->toIso8601String()] : [];

    $this->withToken($token)->postJson($url, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('approval');

    expect($post->refresh()->status)->toBe(PostStatus::Draft)
        ->and($post->scheduled_at)->toBeNull()
        ->and($target->refresh()->status)->toBe($action === 'retry' ? PostTargetStatus::Failed : PostTargetStatus::Pending);
    Bus::assertNotDispatched(PublishPostTarget::class);
})->with(['publish', 'schedule', 'queue', 'retry']);

test('MCP confirmation cannot bypass human approval', function (string $tool): void {
    [$user, $workspace] = ownerActingIn();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    bindTokenToWorkspace($user, $workspace);
    approvalEntryPointSchedule($workspace);
    $this->travelTo(CarbonImmutable::parse('2030-01-07T08:30:00Z'));
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $target = PostTarget::factory()->for($post)->create([
        'status' => $tool === RetryPostTargetTool::class ? PostTargetStatus::Failed : PostTargetStatus::Pending,
    ]);
    Bus::fake();

    ShoutrrrServer::actingAs($user)->tool($tool, [
        'post_id' => $post->id,
        'target_id' => $target->id,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'confirm' => true,
    ])->assertHasErrors();

    expect($post->refresh()->status)->toBe(PostStatus::Draft)
        ->and($post->scheduled_at)->toBeNull()
        ->and($target->refresh()->status)->toBe($tool === RetryPostTargetTool::class ? PostTargetStatus::Failed : PostTargetStatus::Pending);
    Bus::assertNotDispatched(PublishPostTarget::class);
})->with([PublishPostTool::class, SchedulePostTool::class, QueuePostTool::class, RetryPostTargetTool::class]);

test('scheduler leaves an unapproved scheduled post awaiting review', function (): void {
    $workspace = Workspace::factory()->create(['requires_post_approval' => true]);
    $post = Post::factory()->for($workspace)->create([
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);
    PostTarget::factory()->for($post)->create();
    Bus::fake();

    $this->artisan('posts:dispatch-due')->assertSuccessful();

    expect($post->refresh()->status)->toBe(PostStatus::Scheduled);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('web scheduling respects the approved UTC plan and rejects replacement times', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    $post = Post::factory()->for($workspace)->create(['author_id' => $owner->id]);
    PostTarget::factory()->for($post)->create();
    $plannedAt = CarbonImmutable::parse('2030-01-07T09:00:00Z');
    approveEntryPointPost($post, $owner, $plannedAt);

    $this->putJson("/posts/{$post->id}/schedule", ['scheduled_at' => '2030-01-07T14:30:00+05:30'])
        ->assertOk();
    $this->putJson("/posts/{$post->id}/schedule", ['scheduled_at' => $plannedAt->addHour()->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('approval');

    expect($post->refresh()->scheduled_at->equalTo($plannedAt))->toBeTrue()
        ->and(app(PostApprovalService::class)->isApproved($post))->toBeTrue();
});

test('API scheduling rejects a time different from dashboard approved plan', function (): void {
    [$owner, $workspace, $token] = issuedKey();
    $workspace->forceFill(['requires_post_approval' => true, 'owner_id' => $owner->id])->save();
    $workspace->members()->where('user_id', $owner->id)->update(['role' => WorkspaceRole::Owner]);
    $post = Post::factory()->for($workspace)->create(['author_id' => $owner->id]);
    PostTarget::factory()->for($post)->create();
    $plannedAt = now()->toImmutable()->addDay()->startOfMinute();
    approveEntryPointPost($post, $owner, $plannedAt);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/schedule", ['scheduled_at' => $plannedAt->addHour()->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('approval');
    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/schedule", ['scheduled_at' => $plannedAt->toIso8601String()])
        ->assertOk();

    expect($post->refresh()->scheduled_at->equalTo($plannedAt))->toBeTrue();
});

test('MCP scheduling respects dashboard approval and cannot change its publishing plan', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    bindTokenToWorkspace($owner, $workspace);
    $post = Post::factory()->for($workspace)->create(['author_id' => $owner->id]);
    PostTarget::factory()->for($post)->create();
    $plannedAt = now()->toImmutable()->addDay()->startOfMinute();
    approveEntryPointPost($post, $owner, $plannedAt);

    ShoutrrrServer::actingAs($owner)->tool(SchedulePostTool::class, [
        'post_id' => $post->id, 'scheduled_at' => $plannedAt->addHour()->toIso8601String(),
    ])->assertHasErrors();
    ShoutrrrServer::actingAs($owner)->tool(SchedulePostTool::class, [
        'post_id' => $post->id, 'scheduled_at' => $plannedAt->toIso8601String(),
    ])->assertOk();

    expect($post->refresh()->scheduled_at->equalTo($plannedAt))->toBeTrue();
});

test('queueing uses an approved slot and rejects another open slot', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    approvalEntryPointSchedule($workspace);
    $this->travelTo(CarbonImmutable::parse('2030-01-07T08:30:00Z'));
    $post = Post::factory()->for($workspace)->create(['author_id' => $owner->id]);
    PostTarget::factory()->for($post)->create();
    $plannedAt = now()->toImmutable()->setTime(9, 0);
    approveEntryPointPost($post, $owner, $plannedAt);

    $this->postJson("/posts/{$post->id}/queue", ['scheduled_at' => $plannedAt->addWeek()->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('approval');
    $this->postJson("/posts/{$post->id}/queue", ['scheduled_at' => $plannedAt->toIso8601String()])
        ->assertOk();

    expect($post->refresh()->scheduled_at->equalTo($plannedAt))->toBeTrue();
});

test('immediate publish cannot bypass an approved future schedule', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    $post = Post::factory()->for($workspace)->create(['author_id' => $owner->id]);
    PostTarget::factory()->for($post)->create();
    approveEntryPointPost($post, $owner, now()->toImmutable()->addDay());
    Bus::fake();

    $this->postJson("/posts/{$post->id}/publish")->assertUnprocessable()->assertJsonValidationErrors('approval');

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('scheduler dispatches a matching approved plan but skips an altered scheduled time', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    $plannedAt = now()->toImmutable()->subMinute()->startOfMinute();
    $post = Post::factory()->for($workspace)->create(['author_id' => $owner->id]);
    $target = PostTarget::factory()->for($post)->create();
    approveEntryPointPost($post, $owner, $plannedAt);
    $post->forceFill(['status' => PostStatus::Scheduled, 'scheduled_at' => $plannedAt->subMinute()])->save();
    Bus::fake();

    $this->artisan('posts:dispatch-due')->assertSuccessful();
    Bus::assertNotDispatched(PublishPostTarget::class);
    expect($post->refresh()->status)->toBe(PostStatus::Scheduled);

    $post->forceFill(['scheduled_at' => $plannedAt])->save();
    $this->artisan('posts:dispatch-due')->assertSuccessful();

    Bus::assertDispatched(PublishPostTarget::class, fn (PublishPostTarget $job): bool => $job->target->is($target));
    expect($post->refresh()->status)->toBe(PostStatus::Publishing);
});

test('scheduler does not publish a post rescheduled after candidate discovery', function (): void {
    [$owner, $workspace] = ownerActingIn();
    $workspace->forceFill(['requires_post_approval' => true])->save();
    $post = Post::factory()->for($workspace)->create(['author_id' => $owner->id]);
    PostTarget::factory()->for($post)->create();
    $dueAt = now()->toImmutable()->subMinute()->startOfMinute();
    approveEntryPointPost($post, $owner, $dueAt);
    $post->forceFill(['status' => PostStatus::Scheduled, 'scheduled_at' => $dueAt])->save();
    $futureAt = now()->toImmutable()->addDay()->startOfMinute();
    $rescheduled = false;
    Bus::fake();
    DB::listen(function (QueryExecuted $query) use ($post, $owner, $futureAt, &$rescheduled): void {
        if (! $rescheduled && str_starts_with($query->sql, 'select "id" from "posts"')) {
            $rescheduled = true;
            approveEntryPointPost($post, $owner, $futureAt);
            $post->refresh()->forceFill(['status' => PostStatus::Scheduled, 'scheduled_at' => $futureAt])->save();
        }
    });

    $this->artisan('posts:dispatch-due')->assertSuccessful();

    expect($rescheduled)->toBeTrue()
        ->and($post->refresh()->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->equalTo($futureAt))->toBeTrue();
    Bus::assertNotDispatched(PublishPostTarget::class);
});

test('automatic reposting cannot reuse a protected workspace original publication approval', function (): void {
    $workspace = Workspace::factory()->create(['requires_post_approval' => true]);
    $post = Post::factory()->for($workspace)->create(['auto_repost' => true]);
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::X,
        'remote_account_id' => 'approved-brand',
        'capabilities' => ['auto_repost' => ['enabled' => true]],
    ]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'published-once',
        'posted_at' => Date::now()->subHours(200),
    ]);
    Queue::fake();
    $this->mock(RepostConnectorRegistry::class, fn ($mock) => $mock->shouldReceive('for')->never());

    $this->artisan('posts:dispatch-due-reposts')->assertSuccessful();
    app()->call([new RepostPostTarget($target), 'handle']);

    Queue::assertNotPushed(RepostPostTarget::class);
    expect($target->refresh()->reposted_at)->toBeNull();
});

test('a policy enabled during token resolution blocks an in-flight automatic repost', function (): void {
    $workspace = Workspace::factory()->create();
    $post = Post::factory()->for($workspace)->create(['auto_repost' => true]);
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::X,
        'remote_account_id' => 'approved-brand',
        'capabilities' => ['auto_repost' => ['enabled' => true]],
    ]);
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'published-once',
        'posted_at' => Date::now()->subHours(200),
    ]);
    $this->mock(TokenManager::class, function ($mock) use ($workspace): void {
        $mock->shouldReceive('fresh')->once()->andReturnUsing(function () use ($workspace): array {
            $workspace->forceFill(['requires_post_approval' => true])->save();

            return ['access_token' => 'fixture'];
        });
    });
    $this->mock(RepostConnectorRegistry::class, fn ($mock) => $mock->shouldReceive('for')->never());

    app()->call([new RepostPostTarget($target), 'handle']);

    expect($target->refresh()->reposted_at)->toBeNull();
});
