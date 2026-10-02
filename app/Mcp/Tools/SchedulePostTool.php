<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\PostApprovalService;
use App\Support\PostView;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Override;

#[Description('Schedule a post for a specific future time (ISO-8601). Omit scheduled_at or pass null to un-schedule back to draft.')]
class SchedulePostTool extends WorkspaceTool
{
    public function handle(Request $request, WorkspaceSubscriptionGate $subscriptions, PostApprovalService $approvals): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ], [
            'scheduled_at.after' => 'Choose a time in the future — a post cannot be scheduled in the past.',
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }

        if (! $post->status->isAwaitingPublication()) {
            return Response::error('Only draft, scheduled, or missed posts can be scheduled.');
        }

        if (($validated['scheduled_at'] ?? null) !== null && ! $subscriptions->canPublish($post->workspace()->firstOrFail())) {
            return Response::error('This workspace requires an active subscription before publishing.');
        }

        $scheduledAt = $validated['scheduled_at'] ?? null;
        $scheduledAt = $scheduledAt === null ? null : CarbonImmutable::parse($scheduledAt)->utc();

        if ($scheduledAt !== null) {
            try {
                $approvals->assertPlan($post, $scheduledAt);
            } catch (ValidationException $exception) {
                return Response::error($exception->getMessage());
            }
        }

        $claimed = Post::query()->whereKey($post->id)
            ->where('status', $post->status->value)
            ->update([
                'scheduled_at' => $scheduledAt,
                'status' => $scheduledAt === null ? PostStatus::Draft->value : PostStatus::Scheduled->value,
            ]);
        if ($claimed !== 1) {
            return Response::error('This post has already changed. Refresh before scheduling.');
        }

        return Response::text(json_encode(PostView::make($post->fresh(['targets.account', 'media'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to schedule.')->required(),
            'scheduled_at' => $schema->string()->description('Future ISO-8601 time, or null to un-schedule.'),
        ];
    }
}
