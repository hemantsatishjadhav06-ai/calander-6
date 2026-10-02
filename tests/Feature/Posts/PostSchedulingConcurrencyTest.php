<?php

use App\Enums\PostStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\QueuePostTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Services\Billing\WorkspaceSubscriptionGate;

test('scheduling cannot overwrite a post claimed for publication after its state guard', function (string $interface, string $action) {
    [$user, $workspace, $token] = issuedKey();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => PostStatus::Draft]);
    $when = now()->addDay()->startOfHour();
    $schedule = PostingSchedule::factory()->for($workspace)->create(['timezone' => 'UTC']);
    PostingScheduleSlot::factory()->create([
        'posting_schedule_id' => $schedule->id,
        'weekday' => $when->dayOfWeek,
        'hour' => $when->hour,
        'minute' => 0,
    ]);
    $subscriptions = Mockery::mock(WorkspaceSubscriptionGate::class);
    $subscriptions->shouldReceive('canPublish')->once()->andReturnUsing(function () use ($post): bool {
        Post::query()->whereKey($post->id)->update(['status' => PostStatus::Publishing->value]);

        return true;
    });
    app()->instance(WorkspaceSubscriptionGate::class, $subscriptions);
    $payload = ['scheduled_at' => $when->toIso8601String()];

    if ($interface === 'web') {
        $this->actingAs($user);
        ($action === 'schedule'
            ? $this->putJson("/posts/{$post->id}/schedule", $payload)
            : $this->postJson("/posts/{$post->id}/queue", $payload))
            ->assertConflict();
    } elseif ($interface === 'api') {
        $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/{$action}", $payload)->assertConflict();
    } else {
        bindTokenToWorkspace($user, $workspace);
        ShoutrrrServer::actingAs($user)->tool(
            $action === 'schedule' ? SchedulePostTool::class : QueuePostTool::class,
            ['post_id' => $post->id, ...$payload],
        )->assertHasErrors();
    }

    expect($post->refresh()->status)->toBe(PostStatus::Publishing)
        ->and($post->scheduled_at)->toBeNull();
})->with(['web', 'api', 'mcp'])->with(['schedule', 'queue']);

test('scheduling preserves the UTC instant of an ISO time with a timezone offset', function (string $interface) {
    [$user, $workspace, $token] = issuedKey();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => PostStatus::Draft]);
    $payload = ['scheduled_at' => '2030-01-01T15:30:00+05:30'];

    if ($interface === 'web') {
        $this->actingAs($user)->putJson("/posts/{$post->id}/schedule", $payload)->assertOk();
    } elseif ($interface === 'api') {
        $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/schedule", $payload)->assertOk();
    } else {
        bindTokenToWorkspace($user, $workspace);
        ShoutrrrServer::actingAs($user)->tool(SchedulePostTool::class, ['post_id' => $post->id, ...$payload])->assertOk();
    }

    expect($post->refresh()->scheduled_at->toIso8601String())->toBe('2030-01-01T10:00:00+00:00');
})->with(['web', 'api', 'mcp']);
