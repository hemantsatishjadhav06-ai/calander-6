<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncAirtableWorkspace;
use App\Models\AirtableIntegration;
use App\Models\AirtablePostLink;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostWorkflowEvent;
use App\Services\Airtable\AirtableClient;
use App\Services\Airtable\AirtableSyncService;
use App\Services\Posts\PostReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AirtableController extends Controller
{
    public function index(Request $request, AirtableClient $client, PostReviewService $reviews): Response
    {
        $workspaceId = $request->user()->current_workspace_id;
        abort_unless($request->user()->hasAllPermissions(['workspace.read'], $workspaceId), 403);
        $integration = AirtableIntegration::query()->where('workspace_id', $workspaceId)->first();
        $links = $integration ? AirtablePostLink::query()->where('integration_id', $integration->id)->get()->keyBy('post_id') : collect();
        $posts = Post::query()->where('workspace_id', $workspaceId)->where('status', '!=', PostStatus::Deleted->value)
            ->latest()->limit(100)->get();
        $selectedId = $request->string('post')->toString();
        if ($selectedId !== '' && ! $posts->contains('id', $selectedId)) {
            $selected = Post::query()->where('workspace_id', $workspaceId)->findOrFail($selectedId);
            $posts->prepend($selected);
        }

        return Inertia::render('airtable/index', [
            'integration' => $integration ? [
                ...$integration->only(['enabled', 'base_id', 'table_id', 'post_name_field', 'interface_url', 'sync_interval_minutes', 'last_synced_at', 'last_error', 'api_calls', 'cooldown_until', 'lease_until']),
                'token_configured' => $client->configured($integration),
            ] : null,
            'workspaceId' => $workspaceId,
            'canManage' => $request->user()->hasAllPermissions(['workspace.settings.manage'], $workspaceId),
            'selectedPostId' => $selectedId,
            'source' => $request->query('source') === 'airtable' ? 'airtable' : 'dashboard',
            'posts' => $posts->map(function (Post $post) use ($links, $reviews): array {
                $link = $links->get($post->id);

                return [
                    'id' => $post->id,
                    'base_text' => $post->base_text,
                    'targets' => $post->targets->map(fn (PostTarget $target): array => ['id' => $target->id, 'platform' => $target->platform->value, 'handle' => $target->account?->handle, 'sections' => $target->sections, 'format' => $target->format->value])->values()->all(),
                    'media' => $post->media->map(fn (PostMedia $media): array => $media->toView())->values()->all(),
                    'status' => $post->status->value,
                    'review_status' => $reviews->status($post),
                    'revision' => $reviews->revision($post),
                    'review_note' => $post->getAttribute('review_note'),
                    'airtable_record_id' => $link?->record_id,
                    'sync_error' => $link?->sync_error,
                    'conflict' => $link?->conflict,
                ];
            })->values()->all(),
            'audit' => PostWorkflowEvent::query()->where('post_workflow_events.workspace_id', $workspaceId)
                ->leftJoin('users', 'users.id', '=', 'post_workflow_events.actor_id')
                ->orderByDesc('post_workflow_events.created_at')->limit(50)
                ->get(['post_workflow_events.id', 'post_id', 'action', 'source', 'users.name as actor_name', 'post_workflow_events.created_at'])->toArray(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id;
        abort_unless($request->user()->hasAllPermissions(['workspace.settings.manage'], $workspaceId), 403);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'base_id' => ['required', 'string', 'regex:/^app[a-zA-Z0-9]+$/', 'max:100'],
            'table_id' => ['required', 'string', 'regex:/^tbl[a-zA-Z0-9]+$/', 'max:100'],
            'post_name_field' => ['nullable', 'string', 'max:100', Rule::notIn(['App Post ID', 'Text', 'Draft text', 'Draft revision', 'Current revision', 'Review status', 'Review note', 'Publishing status', 'Scheduled at', 'Published at', 'Targets', 'Dashboard URL', 'Review URL', 'Sync status'])],
            'interface_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'sync_interval_minutes' => ['required', 'integer', Rule::in([0, 360, 720, 1440])],
        ]);
        if (! empty($data['interface_url'])) {
            $url = parse_url((string) $data['interface_url']);
            abort_unless(($url['host'] ?? '') === 'airtable.com' && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['port']), 422, 'Use an https://airtable.com interface URL.');
        }
        DB::transaction(function () use ($request, $workspaceId, $data): void {
            $integration = AirtableIntegration::query()->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            abort_if($integration?->lease_until?->isFuture(), 409, 'Wait for the current sync to finish before changing configuration.');
            if ($integration && ($integration->base_id !== $data['base_id'] || $integration->table_id !== $data['table_id'])) {
                abort_if(AirtablePostLink::query()->where('integration_id', $integration->id)->exists(), 422, 'This workspace is already linked. Keep these IDs to preserve history; migration to another base requires an operator-led migration.');
            }
            abort_if(AirtableIntegration::query()->where('base_id', $data['base_id'])->where('table_id', $data['table_id'])->where('workspace_id', '!=', $workspaceId)->exists(), 422, 'This Airtable table is already assigned to another workspace.');
            $integration ??= new AirtableIntegration(['workspace_id' => $workspaceId]);
            $integration->forceFill([...$data, 'configured_by_id' => $request->user()->id, 'next_sync_at' => null])->save();
        });

        return back()->with('success', 'Airtable configuration saved. The dashboard remains available.');
    }

    public function sync(Request $request, AirtableClient $client): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id;
        abort_unless($request->user()->hasAllPermissions(['workspace.settings.manage'], $workspaceId), 403);
        $integration = AirtableIntegration::query()->where('workspace_id', $workspaceId)->firstOrFail();
        abort_unless($integration->enabled && $client->configured($integration), 422, 'Enable this integration and configure its server-side token first.');
        abort_if($integration->lease_until?->isFuture(), 409, 'A sync is already running.');
        abort_if($integration->cooldown_until?->isFuture(), 429, 'Sync is cooling down. Try again after the displayed cooldown.');
        SyncAirtableWorkspace::dispatch($integration->id);

        return back()->with('success', 'Sync requested. Refresh to see its status after the worker finishes.');
    }

    public function review(Request $request, Post $post, PostReviewService $reviews): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['submit', 'approve', 'request_changes'])],
            'revision' => ['required', 'string', 'size:64'],
            'note' => ['nullable', 'string', 'max:5000'],
            'source' => ['required', Rule::in(['dashboard', 'airtable'])],
        ]);
        $reviews->act($post, $request->user(), $data['action'], $data['revision'], $data['note'] ?? null, $data['source']);

        return back()->with('success', 'Review saved for this content revision.');
    }

    public function resolve(Request $request, Post $post, AirtableSyncService $sync, PostReviewService $reviews): RedirectResponse
    {
        abort_unless($request->user()->hasAllPermissions(['workspace.settings.manage'], $post->workspace_id), 403);
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['dashboard', 'airtable'])],
            'revision' => ['required', 'string', 'size:64'],
            'conflict_hash' => ['required', 'string', 'size:64'],
        ]);
        DB::transaction(function () use ($request, $post, $data, $sync, $reviews): void {
            $integration = AirtableIntegration::query()->where('workspace_id', $post->workspace_id)->lockForUpdate()->firstOrFail();
            abort_if($integration->lease_until?->isFuture(), 409, 'Wait for the current sync to finish.');
            $locked = Post::query()->lockForUpdate()->findOrFail($post->id);
            $link = AirtablePostLink::query()->where('integration_id', $integration->id)->where('post_id', $locked->id)->lockForUpdate()->firstOrFail();
            $conflict = $link->conflict;
            abort_unless($conflict && hash_equals($conflict['hash'], $data['conflict_hash']) && hash_equals($reviews->revision($locked), $data['revision']), 409, 'The proposal or dashboard changed. Reload before resolving.');
            if ($data['resolution'] === 'airtable') {
                abort_unless($locked->status === PostStatus::Draft && count($locked->segments) <= 1, 422, 'Un-schedule this post or edit the thread in the dashboard before applying a text proposal.');
                $sync->applyText($locked, $conflict['text'], $request->user()->id);
            }
            $link->forceFill(['proposal_hash' => $conflict['hash'], 'conflict' => null, 'sync_error' => null])->save();
            $reviews->record($locked->fresh(), 'conflict_resolved_'.$data['resolution'], 'dashboard', $request->user()->id);
        });

        return back()->with('success', 'Conflict resolved. Run sync to update the Airtable status.');
    }
}
