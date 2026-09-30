export type ReportFilters = {
    from: string;
    to: string;
    platform: string | null;
    account_id: string | null;
};

export type ReportMetric = { value: number | null; measured_targets: number };
export type ReportRow = {
    post_id: string;
    target_id: string;
    text: string;
    post_status: string;
    target_status: string;
    cohort_at: string;
    published_at: string | null;
    platform: string;
    account_id: string;
    account: string;
    handle: string;
    metrics_status: string;
    captured_at: string | null;
    last_checked_at: string | null;
    supported_fields: string[];
    polling_enabled: boolean;
    exposure_label: string;
    likes: number | null;
    comments: number | null;
    reposts: number | null;
    impressions: number | null;
    engagement: number | null;
};

export type WorkspaceReport = {
    workspace: { id: string; name: string; timezone: string };
    filters: ReportFilters;
    generated_at: string;
    date_basis: string;
    metric_basis: string;
    summary: {
        posts: number;
        published_posts: number;
        targets: number;
        published_targets: number;
        measured_targets: number;
        post_statuses: Record<string, number>;
        target_statuses: Record<string, number>;
        metrics: Record<
            'likes' | 'comments' | 'reposts' | 'impressions' | 'engagement',
            ReportMetric
        >;
        average_engagement: number | null;
        oldest_capture: string | null;
        latest_capture: string | null;
    };
    rows: ReportRow[];
    accounts: { id: string; platform: string; label: string }[];
    platforms: { value: string; label: string; polling_enabled: boolean }[];
};
