<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Metrics\WorkspaceReport;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    [$this->user, $this->workspace] = ownerActingIn();
    $this->workspace->update(['timezone' => 'UTC']);
    Context::add('workspace_id', $this->workspace->id);
    Date::setTestNow('2026-09-30 12:00:00');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Date::setTestNow();
});

/** @param array<string, mixed> $attributes */
function reportTarget(Workspace $workspace, array $attributes = [], ?Post $post = null, ?ConnectedAccount $account = null): PostTarget
{
    $account ??= ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky]);
    $post ??= Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Published, 'published_at' => '2026-09-15 12:00:00']);

    return PostTarget::factory()->create([
        'post_id' => $post->id, 'connected_account_id' => $account->id, 'platform' => $account->platform,
        'status' => PostTargetStatus::Published, 'posted_at' => '2026-09-15 12:00:00',
        'metrics_status' => MetricsStatus::Ok, 'metrics_captured_at' => '2026-09-29 12:00:00',
        'likes' => 0, 'comments' => 0, 'reposts' => 0, 'impressions' => null,
        ...$attributes,
    ]);
}

test('report has an honest empty state and csv headers with no provider calls', function (): void {
    $this->get(route('analytics.report'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('analytics/report')->where('report.workspace.id', $this->workspace->id)
        ->where('report.summary.posts', 0)->where('report.summary.metrics.engagement.value', null)
        ->where('report.summary.average_engagement', null)->where('report.summary.measured_targets', 0)
        ->where('report.filters.from', '2026-09-01')->where('report.filters.to', '2026-09-30')->has('report.rows', 0));
    $response = $this->get(route('analytics.export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect(trim($response->streamedContent()))->toStartWith('workspace,from,to,timezone,generated_at');
    expect(substr_count($response->streamedContent(), "\n"))->toBe(1);
    Http::assertNothingSent();
});

test('report separates zero failed unsupported pending and field availability with explicit denominators', function (): void {
    reportTarget($this->workspace, ['likes' => 10, 'comments' => 2, 'reposts' => 3]);
    reportTarget($this->workspace);
    reportTarget($this->workspace, ['metrics_status' => MetricsStatus::Failed, 'likes' => 999]);
    reportTarget($this->workspace, ['metrics_status' => MetricsStatus::RateLimited, 'likes' => 999]);
    reportTarget($this->workspace, ['metrics_status' => MetricsStatus::Unsupported, 'likes' => 999]);
    reportTarget($this->workspace, ['metrics_status' => null]);
    reportTarget($this->workspace, ['status' => PostTargetStatus::Failed, 'likes' => 999]);
    $discord = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Discord]);
    reportTarget($this->workspace, ['likes' => 5, 'comments' => 999, 'reposts' => 999], account: $discord);
    $personal = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    reportTarget($this->workspace, ['likes' => 999], account: $personal);
    $this->get(route('analytics.report'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('report.summary.targets', 9)->where('report.summary.published_targets', 8)
        ->where('report.summary.measured_targets', 3)->where('report.summary.metrics.engagement.value', 20)
        ->where('report.summary.metrics.comments.value', 2)->where('report.summary.metrics.comments.measured_targets', 2)
        ->where('report.summary.metrics.impressions.value', null)->where('report.summary.average_engagement', 6.67)
        ->where('report.summary.latest_capture', '2026-09-29T12:00:00+00:00'));
    $this->get(route('analytics.report', ['account_id' => $discord->id]))->assertInertia(fn ($page) => $page
        ->where('report.rows.0.likes', 5)->where('report.rows.0.comments', null)->where('report.rows.0.reposts', null)
        ->where('report.rows.0.supported_fields', ['likes']));
    $this->get(route('analytics.report', ['account_id' => $personal->id]))->assertInertia(fn ($page) => $page
        ->where('report.rows.0.engagement', null)->where('report.rows.0.supported_fields', []));
});

test('date platform and account filters constrain summaries and csv to the current workspace', function (): void {
    $kept = reportTarget($this->workspace, ['likes' => 12]);
    reportTarget($this->workspace, ['likes' => 30]);
    $other = Workspace::factory()->create();
    $secret = reportTarget($other, ['likes' => 9000]);
    $filters = ['from' => '2026-09-15', 'to' => '2026-09-15', 'platform' => 'bluesky', 'account_id' => $kept->connected_account_id];
    $this->get(route('analytics.report', $filters))->assertOk()->assertInertia(fn ($page) => $page
        ->where('report.summary.posts', 1)->where('report.summary.metrics.engagement.value', 12)
        ->where('report.rows.0.target_id', $kept->id)->has('report.accounts', 2));
    $csv = $this->get(route('analytics.export', $filters))->assertOk()->streamedContent();
    expect($csv)->toContain($kept->id)->not->toContain($secret->id);
    $this->getJson(route('analytics.report', ['account_id' => $secret->connected_account_id]))->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->getJson(route('analytics.export', ['account_id' => $secret->connected_account_id]))->assertUnprocessable();
    $this->get(route('analytics.report', [...$filters, 'platform' => 'x']))->assertInertia(fn ($page) => $page->where('report.summary.posts', 0));
});

test('report excludes a malformed cross-workspace target association', function (): void {
    $other = Workspace::factory()->create();
    $foreign = ConnectedAccount::factory()->create(['workspace_id' => $other->id]);
    reportTarget($this->workspace, account: $foreign);
    $this->get(route('analytics.report'))->assertOk()->assertInertia(fn ($page) => $page->has('report.rows', 0));
});

test('report uses inclusive workspace dates and the next-day exclusive boundary', function (): void {
    $this->workspace->update(['timezone' => 'Asia/Kolkata']);
    foreach (['2026-09-14 18:29:59', '2026-09-14 18:30:00', '2026-09-15 18:29:59', '2026-09-15 18:30:00'] as $at) {
        $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => PostStatus::Published, 'published_at' => $at]);
        reportTarget($this->workspace, post: $post);
    }
    $this->get(route('analytics.report', ['from' => '2026-09-15', 'to' => '2026-09-15']))->assertOk()->assertInertia(fn ($page) => $page
        ->where('report.summary.posts', 2)->where('report.workspace.timezone', 'Asia/Kolkata'));
});

test('report counts untargeted drafts and failed post statuses without pretending they were published', function (): void {
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => PostStatus::Draft]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => PostStatus::Failed, 'scheduled_at' => '2026-09-15']);
    reportTarget($this->workspace, ['status' => PostTargetStatus::Failed], post: $post);
    $this->get(route('analytics.report'))->assertInertia(fn ($page) => $page
        ->where('report.summary.posts', 2)->where('report.summary.published_posts', 0)
        ->where('report.summary.post_statuses.draft', 1)->where('report.summary.post_statuses.failed', 1)
        ->where('report.summary.metrics.engagement.value', null));
});

test('report rejects malformed dates oversized ranges and unsupported platforms', function (array $filters, string $field): void {
    $this->getJson(route('analytics.report', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->getJson(route('analytics.export', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['from' => '2026-02-31'], 'from'],
    [['from' => '2026-09-30', 'to' => '2026-09-01'], 'to'],
    [['from' => '2024-01-01', 'to' => '2026-01-01'], 'to'],
    [['platform' => 'not-a-provider'], 'platform'],
]);

test('report and export enforce workspace membership', function (): void {
    $outsider = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->actingAs($outsider)->get(route('analytics.report'))->assertForbidden();
    $this->get(route('analytics.export'))->assertForbidden();
});

test('csv quotes content and neutralizes spreadsheet formulas while retaining unavailable blanks and zero', function (): void {
    $account = ConnectedAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky, 'display_name' => '@SUM(1,2)', 'handle' => '+formula']);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'published_at' => '2026-09-15', 'base_text' => " =HYPERLINK(\"https://invalid.test\",\"x\")\nSecond, line"]);
    reportTarget($this->workspace, ['sections' => [$post->base_text]], post: $post, account: $account);
    $response = $this->get(route('analytics.export'))->assertOk()->assertDownload('workspace-report-2026-09-01-2026-09-30.csv');
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $response->streamedContent());
    rewind($stream);
    $header = fgetcsv($stream, escape: '');
    $values = fgetcsv($stream, escape: '');
    fclose($stream);
    $row = array_combine($header, $values);
    expect($row['text'])->toBe("'".$post->base_text)->and($row['account'])->toBe("'@SUM(1,2)")
        ->and($row['handle'])->toBe("'+formula")->and($row['likes'])->toBe('0')
        ->and($row['impressions'])->toBe('')->and($row['metric_basis'])->toContain('lifetime');
});

test('a failed report build cannot return a successful csv', function (): void {
    $this->mock(WorkspaceReport::class)->shouldReceive('build')->once()->andThrow(new RuntimeException('Storage unavailable'));
    $this->get(route('analytics.export'))->assertServerError();
});

test('a successful status without a capture timestamp is unavailable', function (): void {
    reportTarget($this->workspace, ['metrics_captured_at' => null, 'likes' => 99]);
    $this->get(route('analytics.report'))->assertInertia(fn ($page) => $page
        ->where('report.rows.0.likes', null)->where('report.rows.0.captured_at', null)
        ->where('report.summary.measured_targets', 0));
});

test('report and csv use account-specific prepared sections rather than the shared base caption', function (): void {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'published_at' => '2026-09-15', 'base_text' => 'Shared draft caption']);
    $target = reportTarget($this->workspace, ['sections' => ['Account-specific headline', 'A reply for this account'], 'content_override' => ['segments' => ['Account-specific headline', 'A reply for this account']]], post: $post);
    $this->get(route('analytics.report', ['account_id' => $target->connected_account_id]))->assertOk()->assertInertia(fn ($page) => $page->where('report.rows.0.text', "Account-specific headline\n\nA reply for this account"));
    $csv = $this->get(route('analytics.export', ['account_id' => $target->connected_account_id]))->assertOk()->streamedContent();
    expect($csv)->toContain('Account-specific headline')->toContain('A reply for this account')->not->toContain('Shared draft caption');
});
