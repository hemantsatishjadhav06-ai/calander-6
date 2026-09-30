import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AnalyticsReport from '@/pages/analytics/report';
import type { WorkspaceReport } from '@/types/report';

const calls = vi.hoisted(() => ({ get: vi.fn(), download: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { get: calls.get },
}));
vi.mock('@/routes/analytics', () => ({
    index: () => ({ url: '/analytics' }),
    report: () => ({ url: '/analytics/report' }),
    exportMethod: ({ query }: { query: Record<string, string> }) => ({
        url: `/analytics/export?${new URLSearchParams(Object.entries(query).filter(([, value]) => value != null)).toString()}`,
    }),
}));
vi.mock('@/lib/report-export', () => ({ downloadReport: calls.download }));

const report: WorkspaceReport = {
    workspace: { id: 'ws1', name: 'Acme <script>', timezone: 'UTC' },
    filters: {
        from: '2026-09-01',
        to: '2026-09-30',
        platform: null,
        account_id: null,
    },
    generated_at: '2026-09-30T12:00:00Z',
    date_basis: 'Post publication date',
    metric_basis: 'Latest stored lifetime metrics',
    summary: {
        posts: 0,
        published_posts: 0,
        targets: 0,
        published_targets: 0,
        measured_targets: 0,
        post_statuses: { draft: 0, published: 0 },
        target_statuses: {},
        metrics: {
            likes: { value: null, measured_targets: 0 },
            comments: { value: null, measured_targets: 0 },
            reposts: { value: null, measured_targets: 0 },
            impressions: { value: null, measured_targets: 0 },
            engagement: { value: null, measured_targets: 0 },
        },
        average_engagement: null,
        oldest_capture: null,
        latest_capture: null,
    },
    rows: [],
    accounts: [
        { id: 'a1', label: 'Blue team', platform: 'bluesky' },
        { id: 'a2', label: 'X team', platform: 'x' },
    ],
    platforms: [
        { value: 'bluesky', label: 'Bluesky', polling_enabled: true },
        { value: 'x', label: 'X', polling_enabled: false },
    ],
};

beforeEach(() => {
    calls.get.mockReset();
    calls.download.mockReset().mockResolvedValue(undefined);
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('shows unavailable rather than zero for missing measurements and a useful empty report', () => {
    render(<AnalyticsReport report={report} />);
    expect(
        screen.getByText('No publishing targets in this report'),
    ).toBeTruthy();
    expect(screen.getAllByText('Unavailable')).toHaveLength(5);
    expect(screen.getByText('0 of 0 published targets measured')).toBeTruthy();
    expect(document.querySelector('script')).toBeNull();
    expect(screen.getByText('Acme <script> · Workspace report')).toBeTruthy();
});

it('applies dates platform and account together and resets incompatible account choices', () => {
    render(<AnalyticsReport report={report} />);
    fireEvent.change(screen.getByLabelText('From'), {
        target: { value: '2026-09-10' },
    });
    fireEvent.change(screen.getByLabelText('Platform'), {
        target: { value: 'bluesky' },
    });
    expect(screen.queryByRole('option', { name: 'X team · x' })).toBeNull();
    fireEvent.change(screen.getByLabelText('Account'), {
        target: { value: 'a1' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(calls.get).toHaveBeenCalledWith(
        '/analytics/report',
        {
            from: '2026-09-10',
            to: '2026-09-30',
            platform: 'bluesky',
            account_id: 'a1',
        },
        expect.any(Object),
    );
    expect(screen.getByRole('button', { name: 'Loading…' })).toBeDisabled();
    act(() => {
        calls.get.mock.calls[0][2].onFinish();
    });
    fireEvent.change(screen.getByLabelText('Platform'), {
        target: { value: 'x' },
    });
    expect(screen.getByLabelText('Account')).toHaveValue('');
});

it('reports filter validation and network failures without erasing the current report', () => {
    render(<AnalyticsReport report={report} />);
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    const options = calls.get.mock.calls[0][2];
    act(() => {
        options.onError({ to: 'Choose a shorter range.' });
        options.onFinish();
    });
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Choose a shorter range.',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    act(() => {
        options.onNetworkError();
        options.onFinish();
    });
    expect(screen.getByRole('alert')).toHaveTextContent('could not be loaded');
    expect(
        screen.getByText('No publishing targets in this report'),
    ).toBeTruthy();
});

it('exports the displayed filters only and prevents repeat clicks while pending', async () => {
    let finish: () => void = () => {};
    calls.download.mockImplementation(
        () =>
            new Promise<void>((resolve) => {
                finish = resolve;
            }),
    );
    render(
        <AnalyticsReport
            report={{
                ...report,
                filters: {
                    ...report.filters,
                    platform: 'bluesky',
                    account_id: 'a1',
                },
            }}
        />,
    );
    fireEvent.change(screen.getByLabelText('From'), {
        target: { value: '2026-09-20' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }));
    expect(screen.getByRole('button', { name: 'Exporting…' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Exporting…' }));
    expect(calls.download).toHaveBeenCalledTimes(1);
    expect(calls.download).toHaveBeenCalledWith(
        '/analytics/export?from=2026-09-01&to=2026-09-30&platform=bluesky&account_id=a1',
        'workspace-report-2026-09-01-2026-09-30.csv',
    );
    await act(async () => finish());
    expect(
        screen.getByRole('button', { name: 'Export CSV' }),
    ).not.toBeDisabled();
});

it('allows retry after a failed csv export and opens browser print for PDF', async () => {
    calls.download.mockRejectedValueOnce(new Error('offline'));
    const print = vi.spyOn(window, 'print').mockImplementation(() => {});
    render(<AnalyticsReport report={report} />);
    await act(async () =>
        fireEvent.click(screen.getByRole('button', { name: 'Export CSV' })),
    );
    expect(screen.getByRole('alert')).toHaveTextContent(
        'CSV could not be downloaded',
    );
    await act(async () =>
        fireEvent.click(screen.getByRole('button', { name: 'Export CSV' })),
    );
    expect(screen.queryByRole('alert')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Print / Save PDF' }));
    expect(print).toHaveBeenCalledTimes(1);
});

it('updates filter controls when navigation returns different filters', () => {
    const { rerender } = render(<AnalyticsReport report={report} />);
    fireEvent.change(screen.getByLabelText('From'), {
        target: { value: '2026-09-20' },
    });
    rerender(
        <AnalyticsReport
            report={{
                ...report,
                filters: { ...report.filters, from: '2026-09-02' },
            }}
        />,
    );
    expect(screen.getByLabelText('From')).toHaveValue('2026-09-02');
});

it('renders a measured zero distinctly from unavailable platform fields and safely escapes post text', () => {
    render(
        <AnalyticsReport
            report={{
                ...report,
                rows: [
                    {
                        post_id: 'p1',
                        target_id: 't1',
                        text: '<img src=x onerror=alert(1)>',
                        post_status: 'published',
                        target_status: 'published',
                        cohort_at: '2026-09-15T12:00:00Z',
                        published_at: '2026-09-15T12:00:00Z',
                        platform: 'discord',
                        account_id: 'a1',
                        account: 'Company room',
                        handle: 'room',
                        metrics_status: 'ok',
                        captured_at: '2026-09-29T12:00:00Z',
                        last_checked_at: '2026-09-29T12:00:00Z',
                        supported_fields: ['likes'],
                        polling_enabled: false,
                        exposure_label: 'Impressions',
                        likes: 0,
                        comments: null,
                        reposts: null,
                        impressions: null,
                        engagement: 0,
                    },
                ],
            }}
        />,
    );
    const row = screen.getByRole('row', { name: /Company room/ });
    expect(row).toHaveTextContent('0');
    expect(row).toHaveTextContent('Unavailable');
    expect(row).toHaveTextContent('Polling paused');
    expect(row).toHaveTextContent('Collector fields: likes');
    expect(screen.getByText('<img src=x onerror=alert(1)>')).toBeTruthy();
    expect(row.querySelector('img')).toBeNull();
});
