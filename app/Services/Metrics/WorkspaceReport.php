<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Support\InstanceSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

class WorkspaceReport
{
    public const string DATE_BASIS = 'Post publication date; otherwise scheduled date; otherwise creation date, in the workspace timezone.';

    public const string METRIC_BASIS = 'Latest stored lifetime metrics for the selected post cohort, not engagement earned during the date range. Only published targets with a successful capture contribute. Unavailable fields remain blank; zero means a recorded zero. Platform definitions differ; totals are not unique people.';

    public function __construct(private readonly InstanceSettings $settings) {}

    /**
     * @param  array{from: string, to: string, platform: string|null, account_id: string|null}  $filters
     * @return array<string, mixed>
     */
    public function build(Workspace $workspace, array $filters): array
    {
        $timezone = $workspace->timezone ?: 'UTC';
        $from = Date::parse($filters['from'], $timezone)->startOfDay()->utc();
        $until = Date::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc();
        $targetFilter = fn (Builder $query) => $query
            ->whereHas('account', fn (Builder $account) => $account->where('workspace_id', $workspace->id))
            ->when($filters['platform'], fn (Builder $q, string $platform) => $q->where('platform', $platform))
            ->when($filters['account_id'], fn (Builder $q, string $id) => $q->where('connected_account_id', $id));

        $query = Post::query()->where('workspace_id', $workspace->id)
            ->whereRaw('COALESCE(published_at, scheduled_at, created_at) >= ?', [$from])
            ->whereRaw('COALESCE(published_at, scheduled_at, created_at) < ?', [$until])
            ->when($filters['platform'] || $filters['account_id'], fn (Builder $q) => $q->whereHas('targets', $targetFilter))
            ->with(['targets' => fn ($relation) => $targetFilter($relation->getQuery()), 'targets.account'])
            ->orderByDesc('created_at')->orderBy('id');

        $posts = $query->limit(5001)->get();
        if ($posts->count() > 5000 || $posts->sum(fn (Post $post): int => $post->targets->count()) > 10000) {
            throw ValidationException::withMessages(['from' => 'This report is too large. Narrow the dates, platform, or account and try again.']);
        }

        $rows = [];
        $postStatuses = array_fill_keys(array_column(PostStatus::cases(), 'value'), 0);
        $targetStatuses = array_fill_keys(array_column(PostTargetStatus::cases(), 'value'), 0);
        foreach ($posts as $post) {
            $postStatuses[$post->status->value]++;
            foreach ($post->targets as $target) {
                $targetStatuses[$target->status->value]++;
                $rows[] = $this->row($post, $target);
            }
        }

        $rowsCollection = collect($rows);
        $measured = $rowsCollection->whereNotNull('engagement');
        $metrics = [];
        foreach (['likes', 'comments', 'reposts', 'impressions', 'engagement'] as $field) {
            $available = $rowsCollection->whereNotNull($field);
            $metrics[$field] = [
                'value' => $available->isEmpty() ? null : (int) $available->sum($field),
                'measured_targets' => $available->count(),
            ];
        }
        $captured = $measured->pluck('captured_at')->filter()->sort()->values();

        return [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'timezone' => $timezone],
            'filters' => $filters,
            'generated_at' => Date::now()->toIso8601String(),
            'date_basis' => self::DATE_BASIS,
            'metric_basis' => self::METRIC_BASIS,
            'summary' => [
                'posts' => $posts->count(),
                'published_posts' => $posts->filter(fn (Post $p): bool => $p->targets->contains('status', PostTargetStatus::Published))->count(),
                'targets' => count($rows),
                'published_targets' => $targetStatuses[PostTargetStatus::Published->value],
                'measured_targets' => $measured->count(),
                'post_statuses' => $postStatuses,
                'target_statuses' => $targetStatuses,
                'metrics' => $metrics,
                'average_engagement' => $measured->isEmpty() ? null : round((float) $measured->avg('engagement'), 2),
                'oldest_capture' => $captured->first(),
                'latest_capture' => $captured->last(),
            ],
            'rows' => $rows,
            'accounts' => ConnectedAccount::query()->where('workspace_id', $workspace->id)->orderBy('handle')->get()
                ->map(fn (ConnectedAccount $account): array => ['id' => $account->id, 'platform' => $account->platform->value, 'label' => $account->display_name ?: $account->handle])->all(),
            'platforms' => collect(Platform::cases())->map(fn (Platform $platform): array => [
                'value' => $platform->value,
                'label' => $platform->label(),
                'polling_enabled' => $this->settings->postMetricsPollingEnabled($platform),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Post $post, PostTarget $target): array
    {
        $fields = $this->supportedFields($target);
        $isMeasured = $target->status === PostTargetStatus::Published
            && $target->metrics_status === MetricsStatus::Ok
            && $target->metrics_captured_at !== null
            && $fields !== [];
        $values = [];
        foreach (['likes', 'comments', 'reposts', 'impressions'] as $field) {
            $values[$field] = $isMeasured && in_array($field, $fields, true) ? $target->getAttribute($field) : null;
        }

        return [
            'post_id' => $post->id,
            'target_id' => $target->id,
            'text' => implode("\n\n", $target->sections),
            'post_status' => $post->status->value,
            'target_status' => $target->status->value,
            'cohort_at' => ($post->published_at ?? $post->scheduled_at ?? $post->created_at)?->toIso8601String(),
            'published_at' => $target->posted_at?->toIso8601String(),
            'platform' => $target->platform->value,
            'account_id' => $target->connected_account_id,
            'account' => $target->account?->display_name ?: $target->account?->handle,
            'handle' => $target->account?->handle,
            'metrics_status' => $target->metrics_status->value ?? 'not_captured',
            'captured_at' => $isMeasured ? $target->metrics_captured_at->toIso8601String() : null,
            'last_checked_at' => $target->metrics_captured_at?->toIso8601String(),
            'supported_fields' => $fields,
            'polling_enabled' => $this->settings->postMetricsPollingEnabled($target->platform),
            'exposure_label' => match ($target->platform) {
                Platform::Instagram => 'Views or reach (legacy collector)',
                Platform::Threads => 'Views',
                default => 'Impressions',
            },
            ...$values,
            'engagement' => $isMeasured ? array_sum(array_intersect_key($values, array_flip(['likes', 'comments', 'reposts']))) : null,
        ];
    }

    /** @return list<string> */
    private function supportedFields(PostTarget $target): array
    {
        return match ($target->platform) {
            Platform::Discord => ['likes'],
            Platform::Bluesky => ['likes', 'comments', 'reposts'],
            Platform::LinkedIn => $target->account?->isLinkedInOrganization() ? ['likes', 'comments', 'reposts', 'impressions'] : [],
            Platform::X, Platform::Facebook, Platform::Instagram, Platform::Threads => ['likes', 'comments', 'reposts', 'impressions'],
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  resource  $stream
     */
    public function writeCsv(array $report, $stream): void
    {
        $columns = ['workspace', 'from', 'to', 'timezone', 'generated_at', 'date_basis', 'metric_basis', 'post_id', 'target_id', 'text', 'post_status', 'target_status', 'cohort_at', 'published_at', 'platform', 'account', 'handle', 'metrics_status', 'captured_at', 'last_checked_at', 'supported_fields', 'polling_enabled', 'likes', 'comments', 'reposts', 'impressions', 'exposure_label', 'engagement'];
        fputcsv($stream, $columns, escape: '');
        foreach ($report['rows'] as $row) {
            $data = [
                ...$row,
                'workspace' => $report['workspace']['name'],
                'from' => $report['filters']['from'],
                'to' => $report['filters']['to'],
                'timezone' => $report['workspace']['timezone'],
                'generated_at' => $report['generated_at'],
                'date_basis' => $report['date_basis'],
                'metric_basis' => $report['metric_basis'],
                'supported_fields' => implode('|', $row['supported_fields']),
                'polling_enabled' => $row['polling_enabled'] ? 'yes' : 'no',
            ];
            fputcsv($stream, array_map(fn (string $key): string => $this->csvCell($data[$key] ?? null), $columns), escape: '');
        }
    }

    private function csvCell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return preg_match('/^[\x00-\x20]*[=+@-]/u', $text) === 1 || preg_match('/^[\t\r\n]/', $text) === 1 ? "'".$text : $text;
    }
}
