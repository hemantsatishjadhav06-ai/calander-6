import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    EditorialQueue,
    RecurringSeries,
    SchedulingPost,
    WorkflowState,
} from '@/types/scheduling';

const fieldClass =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-sm';

function Errors({ errors }: { errors: Record<string, string> }) {
    const messages = Object.values(errors);
    return messages.length > 0 ? (
        <div
            role="alert"
            className="rounded-md bg-destructive/10 p-3 text-sm text-destructive"
        >
            {messages.join(' ')}
        </div>
    ) : null;
}

export function WorkflowControls({
    workspaceId,
    state,
    revision,
    url,
}: {
    workspaceId: string;
    state: WorkflowState;
    revision: number;
    url: string;
}) {
    const [confirmStop, setConfirmStop] = useState(false);
    const form = useForm({
        expected_workspace_id: workspaceId,
        expected_revision: revision,
        state: 'active',
    });
    function change(nextState: string) {
        form.transform((data) => ({ ...data, state: nextState }));
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => setConfirmStop(false),
        });
    }
    if (state === 'stopped')
        return (
            <span className="text-sm text-muted-foreground">
                Stopped permanently
            </span>
        );
    return (
        <div className="space-y-2">
            <div className="flex flex-wrap gap-2">
                {state !== 'active' && state !== 'completed' && (
                    <Button
                        size="sm"
                        disabled={form.processing}
                        onClick={() => change('active')}
                    >
                        Resume
                    </Button>
                )}
                {state !== 'paused' && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => change('paused')}
                    >
                        Pause
                    </Button>
                )}
                {state !== 'held' && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => change('held')}
                    >
                        Hold
                    </Button>
                )}
                {!confirmStop && (
                    <Button
                        size="sm"
                        variant="destructive"
                        disabled={form.processing}
                        onClick={() => setConfirmStop(true)}
                    >
                        Stop
                    </Button>
                )}
            </div>
            {confirmStop && (
                <div
                    className="space-y-2 rounded-md border border-destructive/30 p-3"
                    role="alert"
                >
                    <p className="text-sm">
                        Stop permanently? Future drafts and queued publishing
                        will stop. Posts already publishing cannot be recalled.
                    </p>
                    <Button
                        size="sm"
                        variant="destructive"
                        disabled={form.processing}
                        onClick={() => change('stopped')}
                    >
                        Confirm stop
                    </Button>{' '}
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => setConfirmStop(false)}
                    >
                        Cancel stop
                    </Button>
                </div>
            )}
            <Errors errors={form.errors} />
        </div>
    );
}

export function QueueForm({
    workspaceId,
    url,
    queue,
    onCancel,
}: {
    workspaceId: string;
    url: string;
    queue?: EditorialQueue;
    onCancel: () => void;
}) {
    const form = useForm({
        expected_workspace_id: workspaceId,
        expected_revision: queue?.revision,
        name: queue?.name ?? '',
        category: queue?.category ?? 'General',
        priority: queue?.priority ?? 50,
    });
    return (
        <form
            aria-label={queue ? 'Edit queue' : 'Create queue'}
            className="space-y-4 rounded-xl border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                const options = { preserveScroll: true, onSuccess: onCancel };
                if (queue) form.put(url, options);
                else form.post(url, options);
            }}
        >
            <fieldset disabled={form.processing} className="space-y-4">
                <label className="block space-y-1 text-sm">
                    Queue name
                    <Input
                        required
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                </label>
                <label className="block space-y-1 text-sm">
                    Category
                    <Input
                        required
                        maxLength={100}
                        value={form.data.category}
                        onChange={(event) =>
                            form.setData('category', event.target.value)
                        }
                    />
                </label>
                <label className="block space-y-1 text-sm">
                    Queue priority (0–100, higher first)
                    <Input
                        required
                        type="number"
                        min={0}
                        max={100}
                        value={form.data.priority}
                        onChange={(event) =>
                            form.setData('priority', Number(event.target.value))
                        }
                    />
                </label>
                <Errors errors={form.errors} />
                <div className="flex gap-2">
                    <Button type="submit">
                        {form.processing ? 'Saving…' : 'Save queue'}
                    </Button>
                    <Button type="button" variant="outline" onClick={onCancel}>
                        Cancel
                    </Button>
                </div>
            </fieldset>
        </form>
    );
}

export function SeriesForm({
    workspaceId,
    url,
    series,
    queues,
    posts,
    onCancel,
}: {
    workspaceId: string;
    url: string;
    series?: RecurringSeries;
    queues: EditorialQueue[];
    posts: SchedulingPost[];
    onCancel: () => void;
}) {
    const form = useForm({
        expected_workspace_id: workspaceId,
        expected_revision: series?.revision,
        name: series?.name ?? '',
        source_post_id: series?.source_post_id ?? '',
        editorial_queue_id: series?.editorial_queue_id ?? '',
        timezone:
            series?.timezone ??
            Intl.DateTimeFormat().resolvedOptions().timeZone,
        frequency: series?.frequency ?? 'weekly',
        interval: series?.interval ?? 1,
        starts_on: series
            ? undefined
            : new Intl.DateTimeFormat('en-CA', {
                  year: 'numeric',
                  month: '2-digit',
                  day: '2-digit',
              }).format(new Date()),
        local_time: series?.local_time ?? '09:00',
        ends_on: series?.ends_on ?? '',
        max_occurrences: series?.max_occurrences?.toString() ?? '',
        lead_hours: series?.lead_hours ?? 168,
    });
    return (
        <form
            aria-label={
                series ? 'Edit recurring series' : 'Create recurring series'
            }
            className="space-y-4 rounded-xl border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    ...data,
                    editorial_queue_id: data.editorial_queue_id || null,
                    ends_on: data.ends_on || null,
                    max_occurrences: data.max_occurrences
                        ? Number(data.max_occurrences)
                        : null,
                }));
                const options = { preserveScroll: true, onSuccess: onCancel };
                if (series) form.put(url, options);
                else form.post(url, options);
            }}
        >
            <fieldset
                disabled={form.processing}
                className="grid gap-4 sm:grid-cols-2"
            >
                <label className="block space-y-1 text-sm">
                    Series name
                    <Input
                        required
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                </label>
                <label className="block space-y-1 text-sm">
                    Source post
                    <select
                        required
                        className={fieldClass}
                        value={form.data.source_post_id}
                        onChange={(event) =>
                            form.setData('source_post_id', event.target.value)
                        }
                    >
                        <option value="">Choose a source</option>
                        {posts.map((post) => (
                            <option key={post.id} value={post.id}>
                                {post.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="block space-y-1 text-sm">
                    Frequency
                    <select
                        className={fieldClass}
                        value={form.data.frequency}
                        onChange={(event) =>
                            form.setData(
                                'frequency',
                                event.target
                                    .value as RecurringSeries['frequency'],
                            )
                        }
                    >
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </label>
                <label className="block space-y-1 text-sm">
                    Repeat every
                    <Input
                        required
                        type="number"
                        min={1}
                        max={366}
                        value={form.data.interval}
                        onChange={(event) =>
                            form.setData('interval', Number(event.target.value))
                        }
                    />
                </label>
                {!series && (
                    <label className="block space-y-1 text-sm">
                        Start date
                        <Input
                            required
                            type="date"
                            value={form.data.starts_on}
                            onChange={(event) =>
                                form.setData('starts_on', event.target.value)
                            }
                        />
                    </label>
                )}
                <label className="block space-y-1 text-sm">
                    Local time
                    <Input
                        required
                        type="time"
                        value={form.data.local_time}
                        onChange={(event) =>
                            form.setData('local_time', event.target.value)
                        }
                    />
                </label>
                <label className="block space-y-1 text-sm">
                    Timezone
                    <Input
                        required
                        placeholder="America/New_York"
                        value={form.data.timezone}
                        onChange={(event) =>
                            form.setData('timezone', event.target.value)
                        }
                    />
                </label>
                <label className="block space-y-1 text-sm">
                    Prepare drafts this many hours ahead
                    <Input
                        required
                        type="number"
                        min={1}
                        max={720}
                        value={form.data.lead_hours}
                        onChange={(event) =>
                            form.setData(
                                'lead_hours',
                                Number(event.target.value),
                            )
                        }
                    />
                </label>
                <label className="block space-y-1 text-sm">
                    End date (optional)
                    <Input
                        type="date"
                        value={form.data.ends_on}
                        onChange={(event) =>
                            form.setData('ends_on', event.target.value)
                        }
                    />
                </label>
                <label className="block space-y-1 text-sm">
                    Maximum drafts (optional)
                    <Input
                        type="number"
                        min={1}
                        max={10000}
                        value={form.data.max_occurrences}
                        onChange={(event) =>
                            form.setData('max_occurrences', event.target.value)
                        }
                    />
                </label>
                <label className="block space-y-1 text-sm sm:col-span-2">
                    Priority queue after review
                    <select
                        className={fieldClass}
                        value={form.data.editorial_queue_id}
                        onChange={(event) =>
                            form.setData(
                                'editorial_queue_id',
                                event.target.value,
                            )
                        }
                    >
                        <option value="">
                            Keep as draft for manual scheduling
                        </option>
                        {queues
                            .filter((queue) => queue.state !== 'stopped')
                            .map((queue) => (
                                <option key={queue.id} value={queue.id}>
                                    {queue.category} · {queue.name}
                                </option>
                            ))}
                    </select>
                </label>
                <p className="text-sm text-muted-foreground sm:col-span-2">
                    Each occurrence copies the latest source content into a
                    separate draft. Every draft requires fresh review. Queue
                    publishing uses your existing posting slots, not the
                    occurrence’s intended time. Month-end dates clamp to the
                    last day; DST gaps move forward, and repeated hours generate
                    only once.
                </p>
                <div className="sm:col-span-2">
                    <Errors errors={form.errors} />
                </div>
                <div className="flex gap-2 sm:col-span-2">
                    <Button type="submit">
                        {form.processing ? 'Saving…' : 'Save series'}
                    </Button>
                    <Button type="button" variant="outline" onClick={onCancel}>
                        Cancel
                    </Button>
                </div>
            </fieldset>
        </form>
    );
}

export function EnqueueForm({
    workspaceId,
    queue,
    posts,
}: {
    workspaceId: string;
    queue: EditorialQueue;
    posts: SchedulingPost[];
}) {
    const form = useForm({
        expected_workspace_id: workspaceId,
        post_id: '',
        priority: 50,
    });
    return (
        <form
            aria-label={`Add draft to ${queue.name}`}
            className="space-y-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(queue.enqueue_url, {
                    preserveScroll: true,
                    onSuccess: () => form.reset('post_id'),
                });
            }}
        >
            <fieldset
                disabled={form.processing || queue.state === 'stopped'}
                className="flex flex-wrap items-end gap-2"
            >
                <label className="min-w-40 flex-1 text-sm">
                    Draft
                    <select
                        required
                        className={fieldClass}
                        value={form.data.post_id}
                        onChange={(event) =>
                            form.setData('post_id', event.target.value)
                        }
                    >
                        <option value="">Choose draft</option>
                        {posts
                            .filter((post) => post.status === 'draft')
                            .map((post) => (
                                <option key={post.id} value={post.id}>
                                    {post.label}
                                </option>
                            ))}
                    </select>
                </label>
                <label className="w-24 text-sm">
                    Priority
                    <Input
                        type="number"
                        required
                        min={0}
                        max={100}
                        value={form.data.priority}
                        onChange={(event) =>
                            form.setData('priority', Number(event.target.value))
                        }
                    />
                </label>
                <Button type="submit">Add draft</Button>
            </fieldset>
            <Errors errors={form.errors} />
        </form>
    );
}

export function WorkflowAction({
    workspaceId,
    url,
    method = 'post',
    children,
}: {
    workspaceId: string;
    url: string;
    method?: 'post' | 'delete';
    children: React.ReactNode;
}) {
    const form = useForm({ expected_workspace_id: workspaceId });
    return (
        <div>
            <Button
                variant="outline"
                size="sm"
                disabled={form.processing}
                onClick={() => {
                    if (method === 'delete')
                        form.delete(url, { preserveScroll: true });
                    else form.post(url, { preserveScroll: true });
                }}
            >
                {form.processing ? 'Working…' : children}
            </Button>
            <Errors errors={form.errors} />
        </div>
    );
}
