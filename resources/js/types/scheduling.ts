export type WorkflowState =
    | 'active'
    | 'paused'
    | 'held'
    | 'stopped'
    | 'completed';
export type EditorialQueue = {
    id: string;
    name: string;
    category: string;
    priority: number;
    state: WorkflowState;
    revision: number;
    update_url: string;
    state_url: string;
    enqueue_url: string;
};
export type RecurringSeries = {
    id: string;
    name: string;
    source_post_id: string | null;
    editorial_queue_id: string | null;
    timezone: string;
    frequency: 'daily' | 'weekly' | 'monthly';
    interval: number;
    starts_on: string;
    next_date: string;
    local_time: string;
    ends_on: string | null;
    max_occurrences: number | null;
    lead_hours: number;
    generated_count: number;
    state: WorkflowState;
    revision: number;
    last_error: string | null;
    update_url: string;
    state_url: string;
    generate_url: string;
};
export type QueueEntry = {
    id: string;
    editorial_queue_id: string;
    post_id: string;
    priority: number;
    status: string;
    blocked_reason: string | null;
    scheduled_at: string | null;
    remove_url: string;
    post_url: string;
};
export type RecurringOccurrence = {
    id: string;
    recurring_post_series_id: string;
    occurrence_key: string;
    intended_at: string;
    post_id: string | null;
    status: string;
    post_url: string | null;
};
export type SchedulingPost = { id: string; label: string; status: string };
export type SchedulingProps = {
    workspaceId: string;
    workspaceName: string;
    canManage: boolean;
    queues: EditorialQueue[];
    series: RecurringSeries[];
    entries: QueueEntry[];
    occurrences: RecurringOccurrence[];
    posts: SchedulingPost[];
    urls: { series: string; queues: string; fill: string; slots: string };
};
