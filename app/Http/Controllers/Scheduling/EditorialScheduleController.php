<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scheduling;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\SaveEditorialQueueRequest;
use App\Http\Requests\Scheduling\SaveRecurringSeriesRequest;
use App\Models\EditorialQueue;
use App\Models\EditorialQueueEntry;
use App\Models\Post;
use App\Models\RecurringPostOccurrence;
use App\Models\RecurringPostSeries;
use App\Models\Workspace;
use App\Services\Scheduling\PriorityQueueScheduler;
use App\Services\Scheduling\RecurringDraftGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EditorialScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspace($request, false);
        $queues = EditorialQueue::query()->where('workspace_id', $workspaceId)->orderByDesc('priority')->orderBy('name')->get();
        $series = RecurringPostSeries::query()->where('workspace_id', $workspaceId)->latest()->get();
        $posts = Post::query()->where('workspace_id', $workspaceId)->whereNotIn('status', [PostStatus::Deleted, PostStatus::Publishing])->latest()->limit(200)->get()
            ->merge(Post::query()->where('workspace_id', $workspaceId)->whereIn('id', $series->pluck('source_post_id')->filter())->where('status', '!=', PostStatus::Deleted)->get())->unique('id')->values();

        return Inertia::render('scheduling/index', [
            'workspaceId' => $workspaceId,
            'workspaceName' => Workspace::query()->findOrFail($workspaceId)->name,
            'canManage' => $request->user()->hasAllPermissions(['workspace.settings.manage'], $workspaceId),
            'queues' => $queues->map(fn (EditorialQueue $queue): array => [...$queue->toArray(), 'update_url' => route('scheduling.queues.update', $queue), 'state_url' => route('scheduling.queues.state', $queue), 'enqueue_url' => route('scheduling.queues.enqueue', $queue)])->all(),
            'series' => $series->map(fn (RecurringPostSeries $item): array => [...$item->toArray(), 'update_url' => route('scheduling.series.update', $item), 'state_url' => route('scheduling.series.state', $item), 'generate_url' => route('scheduling.series.generate', $item)])->all(),
            'entries' => EditorialQueueEntry::query()->where('workspace_id', $workspaceId)->where('status', '!=', 'cancelled')->orderByDesc('priority')->orderBy('created_at')->orderBy('id')->limit(200)->get()->map(fn (EditorialQueueEntry $entry): array => [...$entry->toArray(), 'remove_url' => route('scheduling.entries.destroy', $entry), 'post_url' => route('posts.show', $entry->post_id)])->all(),
            'occurrences' => RecurringPostOccurrence::query()->where('workspace_id', $workspaceId)->latest()->limit(100)->get()->map(fn (RecurringPostOccurrence $occurrence): array => [...$occurrence->toArray(), 'post_url' => $occurrence->post_id === null ? null : route('posts.show', $occurrence->post_id)])->all(),
            'posts' => $posts->map(fn (Post $post): array => ['id' => $post->id, 'label' => $post->excerpt(), 'status' => $post->status->value])->all(),
            'urls' => ['series' => route('scheduling.series.store'), 'queues' => route('scheduling.queues.store'), 'fill' => route('scheduling.fill'), 'slots' => route('queue.show')],
        ]);
    }

    public function storeSeries(SaveRecurringSeriesRequest $request): RedirectResponse
    {
        $workspaceId = $this->workspace($request);
        $data = $request->safe()->except(['expected_workspace_id', 'expected_revision']);
        abort_if(isset($data['ends_on']) && $data['ends_on'] < $data['starts_on'], 422, 'The end date must follow the start date.');
        RecurringPostSeries::create([...$data, 'workspace_id' => $workspaceId, 'next_date' => $data['starts_on']]);

        return back()->with('success', 'Recurring series created. Every occurrence starts as a draft requiring review.');
    }

    public function updateSeries(SaveRecurringSeriesRequest $request, RecurringPostSeries $recurringSeries): RedirectResponse
    {
        $this->workspace($request);
        DB::transaction(function () use ($request, $recurringSeries): void {
            $locked = RecurringPostSeries::query()->lockForUpdate()->findOrFail($recurringSeries->id);
            abort_unless($locked->revision === $request->integer('expected_revision'), 409, 'The series changed. Reload before editing.');
            abort_if(in_array($locked->state, ['stopped', 'completed'], true), 422, 'Create a new series to replace a stopped or completed series.');
            $locked->fill($request->safe()->except(['expected_workspace_id', 'expected_revision']))->forceFill(['revision' => $locked->revision + 1, 'last_error' => null])->save();
        });

        return back()->with('success', 'Series updated. Changes apply to future drafts only.');
    }

    public function seriesState(Request $request, RecurringPostSeries $recurringSeries, RecurringDraftGenerator $generator): RedirectResponse
    {
        $this->workspace($request);
        $this->validateState($request);
        $generator->transition($recurringSeries, $request->string('state')->toString(), $request->integer('expected_revision'));

        return back()->with('success', 'Series state updated. Posts already publishing cannot be recalled.');
    }

    public function generate(Request $request, RecurringPostSeries $recurringSeries, RecurringDraftGenerator $generator): RedirectResponse
    {
        $this->workspace($request);
        $count = $generator->generate($recurringSeries);

        return back()->with('success', $count.' review-required drafts generated.');
    }

    public function storeQueue(SaveEditorialQueueRequest $request): RedirectResponse
    {
        $workspaceId = $this->workspace($request);
        EditorialQueue::create([...$request->safe()->except(['expected_workspace_id', 'expected_revision']), 'workspace_id' => $workspaceId]);

        return back()->with('success', 'Priority queue created.');
    }

    public function updateQueue(SaveEditorialQueueRequest $request, EditorialQueue $editorialQueue): RedirectResponse
    {
        $this->workspace($request);
        DB::transaction(function () use ($request, $editorialQueue): void {
            $locked = EditorialQueue::query()->lockForUpdate()->findOrFail($editorialQueue->id);
            abort_unless($locked->revision === $request->integer('expected_revision'), 409, 'The queue changed. Reload before editing.');
            abort_if($locked->state === 'stopped', 422, 'Create a new queue to replace a stopped queue.');
            $locked->fill($request->safe()->except(['expected_workspace_id', 'expected_revision']))->forceFill(['revision' => $locked->revision + 1])->save();
        });

        return back()->with('success', 'Priority queue updated.');
    }

    public function queueState(Request $request, EditorialQueue $editorialQueue, PriorityQueueScheduler $scheduler): RedirectResponse
    {
        $this->workspace($request);
        $this->validateState($request);
        $scheduler->transition($editorialQueue, $request->string('state')->toString(), $request->integer('expected_revision'));

        return back()->with('success', 'Queue state updated. Posts already publishing cannot be recalled.');
    }

    public function enqueue(Request $request, EditorialQueue $editorialQueue, PriorityQueueScheduler $scheduler): RedirectResponse
    {
        $workspaceId = $this->workspace($request);
        $request->validate(['post_id' => ['required', 'uuid'], 'priority' => ['required', 'integer', 'min:0', 'max:100']]);
        $post = Post::query()->where('workspace_id', $workspaceId)->findOrFail($request->string('post_id')->toString());
        $scheduler->enqueue($editorialQueue, $post, $request->integer('priority'));

        return back()->with('success', 'Draft added to the queue. Publishing checks and review still apply.');
    }

    public function fill(Request $request, PriorityQueueScheduler $scheduler): RedirectResponse
    {
        $workspaceId = $this->workspace($request);
        $count = $scheduler->fill(Workspace::query()->findOrFail($workspaceId));

        return back()->with('success', $count.' approved posts assigned to open posting slots.');
    }

    public function remove(Request $request, EditorialQueueEntry $editorialEntry, PriorityQueueScheduler $scheduler): RedirectResponse
    {
        $this->workspace($request);
        $scheduler->remove($editorialEntry);

        return back()->with('success', 'Post removed from the queue.');
    }

    private function workspace(Request $request, bool $write = true): string
    {
        $id = $request->user()->current_workspace_id;
        abort_if($id === null, 404);
        abort_unless($request->user()->hasAllPermissions([$write ? 'workspace.settings.manage' : 'workspace.read'], $id), 403);
        if ($write) {
            abort_unless($request->input('expected_workspace_id') === $id, 409, 'The active company changed. Reload before making changes.');
        }

        return $id;
    }

    private function validateState(Request $request): void
    {
        $request->validate(['state' => ['required', Rule::in(['active', 'paused', 'held', 'stopped'])], 'expected_revision' => ['required', 'integer', 'min:1']]);
    }
}
