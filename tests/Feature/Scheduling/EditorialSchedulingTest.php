<?php

use App\Enums\ErrorKind;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\EditorialQueue;
use App\Models\EditorialQueueEntry;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\PostTarget;
use App\Models\RecurringPostOccurrence;
use App\Models\RecurringPostSeries;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Creator\CreatorExportFreshness;
use App\Services\Posts\PostReviewService;
use App\Services\Publishing\TokenManager;
use App\Services\Scheduling\EditorialPublishingGuard;
use App\Services\Scheduling\PriorityQueueScheduler;
use App\Services\Scheduling\RecurrenceCalendar;
use App\Services\Scheduling\RecurringDraftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    CarbonImmutable::setTestNow('2026-09-30T08:00:00Z');
    [$this->actor, $this->workspace] = ownerActingIn();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function editorialSource(): Post
{
    $post = Post::factory()->create(['workspace_id' => test()->workspace->id, 'author_id' => test()->actor->id]);
    $account = ConnectedAccount::factory()->create(['workspace_id' => test()->workspace->id, 'platform' => 'bluesky']);
    PostTarget::factory()->create(['post_id' => $post->id, 'connected_account_id' => $account->id, 'platform' => 'bluesky', 'sections' => ['A useful update.']]);

    return $post;
}

function editorialSeries(array $attributes = []): RecurringPostSeries
{
    return RecurringPostSeries::factory()->create(['workspace_id' => test()->workspace->id, 'source_post_id' => editorialSource()->id, ...$attributes]);
}

function editorialSeriesPayload(array $attributes = []): array
{
    return ['expected_workspace_id' => test()->workspace->id, 'source_post_id' => editorialSource()->id, 'name' => 'Weekly tips', 'timezone' => 'America/New_York', 'frequency' => 'weekly', 'interval' => 1, 'starts_on' => '2026-10-01', 'local_time' => '09:00', 'lead_hours' => 168, ...$attributes];
}

function editorialSlots(): void
{
    $schedule = PostingSchedule::factory()->create(['workspace_id' => test()->workspace->id, 'timezone' => 'UTC']);
    foreach ([9, 10, 11] as $hour) {
        PostingScheduleSlot::factory()->create(['posting_schedule_id' => $schedule->id, 'weekday' => 3, 'hour' => $hour, 'minute' => 0]);
    }
}

it('offers company-specific series and queues with normal management permissions', function () {
    $this->get(route('scheduling.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('scheduling/index')->where('workspaceId', $this->workspace->id)->where('canManage', true));
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
    $payload = editorialSeriesPayload();
    $this->actingAs($member)->post(route('scheduling.series.store'), $payload)->assertForbidden();
});

it('validates recurrence boundaries and saves valid timezone-aware series', function () {
    $this->postJson(route('scheduling.series.store'), editorialSeriesPayload(['frequency' => 'hourly', 'interval' => 0, 'timezone' => 'Wrong/Zone', 'local_time' => '25:70']))->assertUnprocessable()->assertJsonValidationErrors(['frequency', 'interval', 'timezone', 'local_time']);
    $this->post(route('scheduling.series.store'), editorialSeriesPayload())->assertRedirect();
    expect(RecurringPostSeries::query()->first()->next_date)->toBe('2026-10-01');
});

it('uses stable local dates across spring gaps and autumn folds', function () {
    $calendar = app(RecurrenceCalendar::class);
    $series = RecurringPostSeries::factory()->make(['timezone' => 'America/New_York', 'starts_on' => '2026-03-08', 'next_date' => '2026-03-08', 'local_time' => '02:30', 'frequency' => 'daily']);
    expect($calendar->instant($series)->toIso8601String())->toBe('2026-03-08T07:30:00+00:00');
    $series->next_date = '2026-11-01';
    $series->local_time = '01:30';
    expect($calendar->instant($series)->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-01 01:30');
    expect($calendar->nextDate($series))->toBe('2026-11-02');
});

it('preserves month-end anchors and interval boundaries', function () {
    $calendar = app(RecurrenceCalendar::class);
    $series = RecurringPostSeries::factory()->make(['starts_on' => '2028-01-31', 'next_date' => '2028-01-31', 'frequency' => 'monthly']);
    expect($calendar->nextDate($series))->toBe('2028-02-29');
    $series->next_date = '2028-02-29';
    expect($calendar->nextDate($series))->toBe('2028-03-31');
    $series->interval = 2;
    expect($calendar->nextDate($series))->toBe('2028-04-30');
});

it('generates one fresh review-required draft per occurrence despite repeated due selection', function () {
    $series = editorialSeries(['max_occurrences' => 1]);
    $staleSelection = $series->replicate();
    $staleSelection->id = $series->id;
    $generator = app(RecurringDraftGenerator::class);
    expect($generator->generate($series))->toBe(1);
    expect($generator->generate($staleSelection))->toBe(0);
    $occurrence = RecurringPostOccurrence::query()->sole();
    $draft = Post::query()->findOrFail($occurrence->post_id);
    expect($draft->status)->toBe(PostStatus::Draft)->and($draft->scheduled_at)->toBeNull()->and((bool) $draft->getAttribute('review_required'))->toBeTrue();
    expect(app(PostReviewService::class)->status($draft))->toBe('pending');
    expect(app(PostReviewService::class)->canPublish($draft))->toBeFalse();
    expect($draft->targets()->count())->toBe(1);
    expect($series->refresh()->state)->toBe('completed');
    Queue::assertNothingPushed();
});

it('keeps deleted occurrence tombstones and never regenerates their drafts', function () {
    $series = editorialSeries(['max_occurrences' => 1]);
    app(RecurringDraftGenerator::class)->generate($series);
    $occurrence = RecurringPostOccurrence::query()->sole();
    Post::query()->findOrFail($occurrence->post_id)->delete();
    $series->forceFill(['state' => 'active', 'next_date' => $occurrence->occurrence_key, 'max_occurrences' => 2])->save();
    expect(app(RecurringDraftGenerator::class)->generate($series))->toBe(0);
    expect(RecurringPostOccurrence::query()->count())->toBe(1)->and($occurrence->refresh()->post_id)->toBeNull();
});

it('enforces occurrence uniqueness in the database', function () {
    $series = editorialSeries();
    $occurrence = RecurringPostOccurrence::factory()->create(['workspace_id' => $this->workspace->id, 'recurring_post_series_id' => $series->id]);
    expect(fn () => RecurringPostOccurrence::factory()->create(['workspace_id' => $this->workspace->id, 'recurring_post_series_id' => $series->id, 'occurrence_key' => $occurrence->occurrence_key]))->toThrow(QueryException::class);
});

it('holds missing sources and refuses stale creator assets without creating drafts', function () {
    $series = editorialSeries();
    Post::query()->findOrFail($series->source_post_id)->delete();
    expect(app(RecurringDraftGenerator::class)->generate($series))->toBe(0)->and($series->refresh()->state)->toBe('held');
    $fresh = editorialSeries();
    $this->mock(CreatorExportFreshness::class, fn ($mock) => $mock->shouldReceive('hasStaleExports')->andReturnTrue());
    expect(app(RecurringDraftGenerator::class)->generate($fresh))->toBe(0)->and($fresh->refresh()->last_error)->not->toBeNull();
    expect(RecurringPostOccurrence::query()->count())->toBe(0);
});

it('pauses without catchup but holds the next date and stops permanently', function () {
    $generator = app(RecurringDraftGenerator::class);
    $paused = editorialSeries(['state' => 'paused', 'next_date' => '2026-09-01', 'frequency' => 'daily']);
    $generator->transition($paused, 'active', 1);
    expect($paused->refresh()->next_date)->toBe('2026-09-30');
    $held = editorialSeries(['state' => 'held', 'next_date' => '2026-09-01']);
    $generator->transition($held, 'active', 1);
    expect($held->refresh()->next_date)->toBe('2026-09-01');
    $generator->transition($held, 'stopped', 2);
    expect($generator->generate($held))->toBe(0);
    $this->postJson(route('scheduling.series.state', $held), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 3, 'state' => 'active'])->assertUnprocessable();
});

it('fills existing open slots in queue and entry priority order without duplicate promotion', function () {
    editorialSlots();
    $high = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id, 'priority' => 90, 'category' => 'Announcements']);
    $low = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id, 'priority' => 10, 'category' => 'Education']);
    $first = editorialSource();
    $second = editorialSource();
    $third = editorialSource();
    $scheduler = app(PriorityQueueScheduler::class);
    $scheduler->enqueue($low, $third, 100);
    $scheduler->enqueue($high, $second, 10);
    $scheduler->enqueue($high, $first, 90);
    expect($scheduler->fill($this->workspace))->toBe(3)->and($scheduler->fill($this->workspace))->toBe(0);
    expect($first->refresh()->scheduled_at->format('H:i'))->toBe('09:00')->and($second->refresh()->scheduled_at->format('H:i'))->toBe('10:00')->and($third->refresh()->scheduled_at->format('H:i'))->toBe('11:00');
    Queue::assertNothingPushed();
});

it('keeps required review drafts waiting and respects occupied slots', function () {
    editorialSlots();
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => PostStatus::Scheduled, 'scheduled_at' => '2026-09-30 09:00:00']);
    $queue = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id]);
    $post = editorialSource();
    $reviews = app(PostReviewService::class);
    $post->forceFill(['review_required' => true, 'review_status' => 'pending', 'review_revision' => $reviews->revision($post)])->save();
    $scheduler = app(PriorityQueueScheduler::class);
    $entry = $scheduler->enqueue($queue, $post, 50);
    expect($scheduler->fill($this->workspace))->toBe(0)->and($entry->refresh()->blocked_reason)->not->toBeNull();
    $reviews->act($post, $this->actor, 'approve', $reviews->revision($post), null, 'test');
    expect($scheduler->fill($this->workspace))->toBe(1)->and($post->refresh()->scheduled_at->format('H:i'))->toBe('10:00');
});

it('returns held scheduled posts to draft and rechecks their exact approval before resuming', function () {
    editorialSlots();
    $queue = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id]);
    $post = editorialSource();
    $reviews = app(PostReviewService::class);
    $post->forceFill(['review_required' => true, 'review_status' => 'approved', 'review_revision' => $reviews->revision($post)])->save();
    $scheduler = app(PriorityQueueScheduler::class);
    $scheduler->enqueue($queue, $post, 50);
    $scheduler->fill($this->workspace);
    $scheduler->transition($queue, 'held', 1);
    expect($post->refresh()->status)->toBe(PostStatus::Draft)->and(app(EditorialPublishingGuard::class)->allows($post))->toBeFalse();
    $post->forceFill(['segments' => ['Changed after approval']])->save();
    $scheduler->transition($queue, 'active', 2);
    expect($scheduler->fill($this->workspace))->toBe(0)->and($reviews->status($post))->toBe('stale');
});

it('removes scheduled queue entries safely and rejects stale state changes', function () {
    editorialSlots();
    $queue = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id]);
    $post = editorialSource();
    $scheduler = app(PriorityQueueScheduler::class);
    $entry = $scheduler->enqueue($queue, $post, 50);
    $scheduler->fill($this->workspace);
    $this->delete(route('scheduling.entries.destroy', $entry), ['expected_workspace_id' => $this->workspace->id])->assertRedirect();
    expect($post->refresh()->status)->toBe(PostStatus::Draft)->and($entry->refresh()->status)->toBe('cancelled');
    $this->postJson(route('scheduling.queues.state', $queue), ['expected_workspace_id' => $this->workspace->id, 'expected_revision' => 99, 'state' => 'paused'])->assertConflict();
});

it('blocks an already queued publish worker when its series is held', function () {
    Notification::fake();
    $series = editorialSeries(['max_occurrences' => 1]);
    app(RecurringDraftGenerator::class)->generate($series);
    $draft = Post::query()->findOrFail(RecurringPostOccurrence::query()->sole()->post_id);
    $reviews = app(PostReviewService::class);
    $draft->forceFill(['review_required' => true, 'review_status' => 'approved', 'review_revision' => $reviews->revision($draft), 'status' => PostStatus::Scheduled, 'scheduled_at' => now()])->save();
    $series->forceFill(['state' => 'held'])->save();
    $target = $draft->targets()->firstOrFail();
    $this->mock(TokenManager::class, fn ($mock) => $mock->shouldNotReceive('fresh'));
    bindConnector(fn () => throw new RuntimeException('Held occurrences must never reach a connector.'));

    app()->call([new PublishPostTarget($target), 'handle']);

    expect($target->fresh()->error_kind)->toBe(ErrorKind::Validation);
    Http::assertNothingSent();
});

it('can hold or stop generated drafts even after a series reaches its occurrence limit', function () {
    $series = editorialSeries(['max_occurrences' => 1]);
    $generator = app(RecurringDraftGenerator::class);
    $generator->generate($series);
    expect($series->refresh()->state)->toBe('completed');
    $generator->transition($series, 'held', 1);
    $draft = Post::query()->findOrFail(RecurringPostOccurrence::query()->sole()->post_id);
    expect(app(EditorialPublishingGuard::class)->allows($draft))->toBeFalse();
    $generator->transition($series, 'stopped', 2);
    expect($series->refresh()->state)->toBe('stopped');
});

it('does not starve an approved post behind more than one hundred blocked entries', function () {
    editorialSlots();
    $queue = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id]);
    foreach (Post::factory()->count(100)->create(['workspace_id' => $this->workspace->id, 'author_id' => $this->actor->id]) as $blocked) {
        EditorialQueueEntry::factory()->create(['workspace_id' => $this->workspace->id, 'editorial_queue_id' => $queue->id, 'post_id' => $blocked->id, 'priority' => 100]);
    }
    $ready = editorialSource();
    $scheduler = app(PriorityQueueScheduler::class);
    $scheduler->enqueue($queue, $ready, 1);

    expect($scheduler->fill($this->workspace))->toBe(1)->and($ready->refresh()->status)->toBe(PostStatus::Scheduled);
});

it('skips elapsed pre-generated drafts on resume from pause without publishing the backlog', function () {
    $queue = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id]);
    $series = editorialSeries(['editorial_queue_id' => $queue->id, 'frequency' => 'daily', 'max_occurrences' => 3]);
    $generator = app(RecurringDraftGenerator::class);
    $generator->generate($series);
    $generator->transition($series, 'paused', 1);
    CarbonImmutable::setTestNow('2026-10-04T10:00:00Z');
    $generator->transition($series, 'active', 2);

    expect(RecurringPostOccurrence::query()->where('status', 'skipped')->count())->toBe(3);
    expect(EditorialQueueEntry::query()->where('status', 'waiting')->count())->toBe(0);
    foreach (RecurringPostOccurrence::query()->get() as $occurrence) {
        expect(app(EditorialPublishingGuard::class)->allows(Post::query()->findOrFail($occurrence->post_id)))->toBeFalse();
    }
});

it('reconciles publication outcomes and does not silently requeue a manually unscheduled draft', function () {
    editorialSlots();
    $queue = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id]);
    $published = editorialSource();
    $cancelled = editorialSource();
    $scheduler = app(PriorityQueueScheduler::class);
    $first = $scheduler->enqueue($queue, $published, 80);
    $second = $scheduler->enqueue($queue, $cancelled, 50);
    $scheduler->fill($this->workspace);
    $published->forceFill(['status' => PostStatus::Published])->save();
    $cancelled->forceFill(['status' => PostStatus::Draft, 'scheduled_at' => null])->save();

    expect($scheduler->fill($this->workspace))->toBe(0);
    expect($first->refresh()->status)->toBe('published')->and($second->refresh()->status)->toBe('cancelled');
});

it('keeps existing recurring sources selectable after more than two hundred newer drafts', function () {
    $series = editorialSeries();
    Post::factory()->count(201)->create(['workspace_id' => $this->workspace->id, 'author_id' => $this->actor->id, 'created_at' => now()->addMinute()]);

    $this->get(route('scheduling.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('posts', fn ($posts) => collect($posts)->contains('id', $series->source_post_id)));
});

it('validates start dates in the selected timezone near a UTC date boundary', function () {
    CarbonImmutable::setTestNow('2026-10-01T01:00:00Z');
    $this->post(route('scheduling.series.store'), editorialSeriesPayload(['timezone' => 'America/Los_Angeles', 'starts_on' => '2026-09-30']))->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson(route('scheduling.series.store'), editorialSeriesPayload(['timezone' => 'Asia/Tokyo', 'starts_on' => '2026-09-30']))->assertUnprocessable()->assertJsonValidationErrors('starts_on');
});

it('retains queue safeguards when a waiting draft is scheduled manually', function () {
    $queue = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id]);
    $post = editorialSource();
    $scheduler = app(PriorityQueueScheduler::class);
    $entry = $scheduler->enqueue($queue, $post, 50);
    $post->forceFill(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDay()])->save();
    $scheduler->fill($this->workspace);
    expect($entry->refresh()->status)->toBe('scheduled');
    $scheduler->transition($queue, 'held', 1);
    expect($post->refresh()->status)->toBe(PostStatus::Draft)->and(app(EditorialPublishingGuard::class)->allows($post))->toBeFalse();
});

it('never promotes a recurring draft before its intended time and keeps earlier slots available', function () {
    editorialSlots();
    $queue = EditorialQueue::factory()->create(['workspace_id' => $this->workspace->id]);
    $recurring = editorialSource();
    $ordinary = editorialSource();
    $series = editorialSeries();
    RecurringPostOccurrence::factory()->create(['workspace_id' => $this->workspace->id, 'recurring_post_series_id' => $series->id, 'post_id' => $recurring->id, 'intended_at' => CarbonImmutable::parse('2026-09-30T10:00:00Z')]);
    $scheduler = app(PriorityQueueScheduler::class);
    $scheduler->enqueue($queue, $recurring, 100);
    $scheduler->enqueue($queue, $ordinary, 10);
    expect($scheduler->fill($this->workspace))->toBe(2)
        ->and($recurring->fresh()->scheduled_at->format('H:i'))->toBe('10:00')
        ->and($ordinary->fresh()->scheduled_at->format('H:i'))->toBe('09:00');
});
