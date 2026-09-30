import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { downloadReport } from '@/lib/report-export';
import {
    index as analyticsRoute,
    report as reportRoute,
    exportMethod as exportRoute,
} from '@/routes/analytics';
import type { ReportFilters, WorkspaceReport } from '@/types/report';

const number = (value: number | null) =>
    value === null ? 'Unavailable' : value.toLocaleString();
const label = (value: string) => value.replaceAll('_', ' ');
const timestamp = (value: string | null, timezone: string) =>
    value === null
        ? 'No successful capture'
        : new Intl.DateTimeFormat('en', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: timezone,
          }).format(new Date(value));

function ReportFiltersForm({
    filters,
    accounts,
    platforms,
}: Pick<WorkspaceReport, 'filters' | 'accounts' | 'platforms'>) {
    const [draft, setDraft] = useState<ReportFilters>(filters);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setError('');
        router.get(reportRoute().url, draft, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors).join(' ')),
            onNetworkError: () => {
                setError('The report could not be loaded. Try again.');
                return false;
            },
            onHttpException: () => {
                setError(
                    'The report could not be loaded. Check your session and try again.',
                );
                return false;
            },
            onFinish: () => setBusy(false),
        });
    }
    return (
        <form
            onSubmit={submit}
            className="report-controls space-y-3 rounded-2xl border bg-card p-4"
        >
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <label className="space-y-1 text-sm">
                    From
                    <Input
                        required
                        type="date"
                        value={draft.from}
                        onChange={(e) =>
                            setDraft({ ...draft, from: e.target.value })
                        }
                    />
                </label>
                <label className="space-y-1 text-sm">
                    Through
                    <Input
                        required
                        type="date"
                        min={draft.from}
                        value={draft.to}
                        onChange={(e) =>
                            setDraft({ ...draft, to: e.target.value })
                        }
                    />
                </label>
                <label className="space-y-1 text-sm">
                    Platform
                    <select
                        className="h-9 w-full rounded-lg border bg-background px-2"
                        value={draft.platform ?? ''}
                        onChange={(e) =>
                            setDraft({
                                ...draft,
                                platform: e.target.value || null,
                                account_id: null,
                            })
                        }
                    >
                        <option value="">All platforms</option>
                        {platforms.map((p) => (
                            <option key={p.value} value={p.value}>
                                {p.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="space-y-1 text-sm">
                    Account
                    <select
                        className="h-9 w-full rounded-lg border bg-background px-2"
                        value={draft.account_id ?? ''}
                        onChange={(e) =>
                            setDraft({
                                ...draft,
                                account_id: e.target.value || null,
                            })
                        }
                    >
                        <option value="">All accounts</option>
                        {accounts
                            .filter(
                                (a) =>
                                    !draft.platform ||
                                    a.platform === draft.platform,
                            )
                            .map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.label} · {a.platform}
                                </option>
                            ))}
                    </select>
                </label>
                <Button className="self-end" type="submit" disabled={busy}>
                    {busy ? 'Loading…' : 'Apply filters'}
                </Button>
            </div>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </form>
    );
}

export default function AnalyticsReport({
    report,
}: {
    report: WorkspaceReport;
}) {
    const [exporting, setExporting] = useState(false);
    const [error, setError] = useState('');
    const { summary, workspace, filters } = report;
    async function exportCsv() {
        if (exporting) return;
        setExporting(true);
        setError('');
        try {
            await downloadReport(
                exportRoute({ query: filters }).url,
                `workspace-report-${filters.from}-${filters.to}.csv`,
            );
        } catch {
            setError(
                'The CSV could not be downloaded. Check your session and filters, then try again.',
            );
        } finally {
            setExporting(false);
        }
    }
    const account = report.accounts.find((a) => a.id === filters.account_id);
    return (
        <>
            <Head title="Workspace report" />
            <style>{`@media print { @page { size: landscape; margin: 12mm; } :is(body, body *):has(#workspace-report) { display: block !important; position: static !important; overflow: visible !important; min-height: 0 !important; height: auto !important; margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: none !important; } :is(body, body *):has(#workspace-report) > :not(:has(#workspace-report)):not(#workspace-report) { display: none !important; } #workspace-report { position: static; width: 100%; max-width: none; padding: 0; color: #111; background: white; font-size: 10px; } #workspace-report .report-controls { display: none !important; } #workspace-report table { width: 100%; font-size: 9px; } #workspace-report th, #workspace-report td { padding: 5px; } #workspace-report tr, #workspace-report .report-card { break-inside: avoid; } #workspace-report thead { display: table-header-group; } #workspace-report .line-clamp-3 { display: block; -webkit-line-clamp: unset; overflow: visible; } #workspace-report .report-scroll { overflow: visible; } #workspace-report .report-card { box-shadow: none; color: #111; background: white; } #workspace-report .text-muted-foreground { color: #555; } }`}</style>
            <div
                id="workspace-report"
                className="mx-auto max-w-7xl space-y-6 px-4 pt-6 pb-16 sm:px-6"
            >
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-sm font-medium text-muted-foreground">
                            {workspace.name} · Workspace report
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold tracking-tight">
                            Publishing & performance
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {filters.from} – {filters.to} · {workspace.timezone}{' '}
                            ·{' '}
                            {filters.platform
                                ? report.platforms.find(
                                      (p) => p.value === filters.platform,
                                  )?.label
                                : 'All platforms'}{' '}
                            · {account?.label ?? 'All accounts'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Generated{' '}
                            {timestamp(report.generated_at, workspace.timezone)}
                        </p>
                    </div>
                    <div className="report-controls flex flex-wrap gap-2">
                        <Link
                            className="px-3 py-2 text-sm underline"
                            href={analyticsRoute().url}
                        >
                            Analytics
                        </Link>
                        <Button
                            variant="outline"
                            onClick={() => window.print()}
                        >
                            Print / Save PDF
                        </Button>
                        <Button
                            disabled={exporting}
                            onClick={() => void exportCsv()}
                        >
                            {exporting ? 'Exporting…' : 'Export CSV'}
                        </Button>
                    </div>
                </div>
                <ReportFiltersForm
                    key={`${workspace.id}:${JSON.stringify(filters)}`}
                    {...report}
                />
                {error && (
                    <p
                        role="alert"
                        className="report-controls text-sm text-destructive"
                    >
                        {error}
                    </p>
                )}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        [
                            'Posts in cohort',
                            number(summary.posts),
                            `${summary.published_posts} with a published matching target`,
                        ],
                        [
                            'Published targets',
                            number(summary.published_targets),
                            `${summary.targets} matching targets across all statuses`,
                        ],
                        [
                            'Recorded engagement',
                            number(summary.metrics.engagement.value),
                            `${summary.measured_targets} of ${summary.published_targets} published targets measured`,
                        ],
                        [
                            'Average / measured target',
                            number(summary.average_engagement),
                            `Engagement ÷ ${summary.measured_targets} measured targets`,
                        ],
                    ].map(([title, value, detail]) => (
                        <Card key={title} className="report-card gap-2 p-5">
                            <p className="text-sm text-muted-foreground">
                                {title}
                            </p>
                            <p className="text-3xl font-semibold tabular-nums">
                                {value}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {detail}
                            </p>
                        </Card>
                    ))}
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="report-card gap-3 p-5">
                        <h2 className="font-semibold">Post status</h2>
                        <div className="flex flex-wrap gap-3">
                            {Object.entries(summary.post_statuses).map(
                                ([status, count]) => (
                                    <span
                                        key={status}
                                        className="rounded-lg bg-muted px-3 py-2 text-sm capitalize"
                                    >
                                        {label(status)}{' '}
                                        <strong className="ml-2">
                                            {count}
                                        </strong>
                                    </span>
                                ),
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Distinct posts after applying platform and account
                            filters. Posts without targets appear only when no
                            destination filter is applied.
                        </p>
                    </Card>
                    <Card className="report-card gap-3 p-5">
                        <h2 className="font-semibold">Available engagement</h2>
                        <dl className="grid grid-cols-3 gap-3">
                            {(['likes', 'comments', 'reposts'] as const).map(
                                (field) => (
                                    <div key={field}>
                                        <dt className="text-sm text-muted-foreground capitalize">
                                            {field === 'likes'
                                                ? 'Likes / reactions'
                                                : field === 'reposts'
                                                  ? 'Reposts / shares'
                                                  : field}
                                        </dt>
                                        <dd className="text-xl font-semibold">
                                            {number(
                                                summary.metrics[field].value,
                                            )}
                                        </dd>
                                        <dd className="text-xs text-muted-foreground">
                                            {
                                                summary.metrics[field]
                                                    .measured_targets
                                            }{' '}
                                            measured targets
                                        </dd>
                                    </div>
                                ),
                            )}
                        </dl>
                    </Card>
                </div>
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">
                        Matching publishing targets
                    </h2>
                    {report.rows.length === 0 ? (
                        <div className="rounded-2xl border border-dashed p-8 text-center">
                            <p className="font-medium">
                                No publishing targets in this report
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Try another date range, platform, or account.
                                Untargeted drafts can still appear in the post
                                count.
                            </p>
                        </div>
                    ) : (
                        <div className="report-scroll overflow-x-auto rounded-xl border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        {[
                                            'Post / account',
                                            'Status',
                                            'Likes / reactions',
                                            'Comments',
                                            'Reposts / shares',
                                            'Exposure',
                                            'Capture / availability',
                                        ].map((title) => (
                                            <th
                                                key={title}
                                                className="p-3 font-medium"
                                            >
                                                {title}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {report.rows.map((row) => (
                                        <tr key={row.target_id}>
                                            <td className="max-w-xs p-3 align-top">
                                                <p className="line-clamp-3 break-words whitespace-pre-wrap">
                                                    {row.text || 'Media post'}
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {row.account} ·{' '}
                                                    {row.platform}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Cohort:{' '}
                                                    {timestamp(
                                                        row.cohort_at,
                                                        workspace.timezone,
                                                    )}
                                                </p>
                                            </td>
                                            <td className="p-3 align-top capitalize">
                                                {label(row.target_status)}
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    Post:{' '}
                                                    {label(row.post_status)}
                                                </p>
                                            </td>
                                            {(
                                                [
                                                    'likes',
                                                    'comments',
                                                    'reposts',
                                                ] as const
                                            ).map((field) => (
                                                <td
                                                    key={field}
                                                    className="p-3 align-top tabular-nums"
                                                >
                                                    {number(row[field])}
                                                </td>
                                            ))}
                                            <td className="p-3 align-top tabular-nums">
                                                {number(row.impressions)}
                                                <p className="text-xs text-muted-foreground">
                                                    {row.exposure_label}
                                                </p>
                                            </td>
                                            <td className="max-w-xs p-3 align-top text-xs">
                                                <p>
                                                    {timestamp(
                                                        row.captured_at,
                                                        workspace.timezone,
                                                    )}
                                                </p>
                                                <p className="mt-1 capitalize">
                                                    {label(row.metrics_status)}
                                                    {!row.polling_enabled &&
                                                        ' · Polling paused'}
                                                </p>
                                                <p className="mt-1 text-muted-foreground">
                                                    Collector fields:{' '}
                                                    {row.supported_fields.length
                                                        ? row.supported_fields.join(
                                                              ', ',
                                                          )
                                                        : 'None for this account'}
                                                </p>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
                <footer className="space-y-2 border-t pt-4 text-xs text-muted-foreground">
                    <p>
                        <strong>Reading this report:</strong>{' '}
                        {report.date_basis} {report.metric_basis}
                    </p>
                    <p>
                        Engagement adds available likes/reactions,
                        comments/replies, and reposts/shares. Discord provides
                        reactions only; LinkedIn personal profiles provide no
                        metrics. Bluesky reposts include quotes; Threads reposts
                        exclude quotes. Exposure is shown per row because views,
                        reach, and impressions are not interchangeable.
                    </p>
                    <p>
                        Successful captures:{' '}
                        {timestamp(summary.oldest_capture, workspace.timezone)}{' '}
                        to{' '}
                        {timestamp(summary.latest_capture, workspace.timezone)}.
                        Polling may be paused, delayed, restricted, or
                        unsuccessful. Historical collector data is reported as
                        stored, including any legacy limitations.
                    </p>
                    <p className="report-controls">
                        Use Print / Save PDF and select your browser’s Save as
                        PDF destination. CSV contains one row per matching
                        publishing target; blank metric cells mean unavailable.
                    </p>
                </footer>
            </div>
        </>
    );
}

AnalyticsReport.layout = {
    breadcrumbs: [
        { title: 'Analytics', href: analyticsRoute().url },
        { title: 'Report', href: reportRoute().url },
    ],
};
