import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import {
    EnqueueForm,
    QueueForm,
    SeriesForm,
    WorkflowAction,
    WorkflowControls,
} from '@/components/scheduling/workflow-forms';
import { Button } from '@/components/ui/button';
import type { SchedulingProps } from '@/types/scheduling';

export default function SchedulingIndex(props: SchedulingProps) {
    return <SchedulingWorkspace key={props.workspaceId} {...props} />;
}

export function SchedulingWorkspace({
    workspaceId,
    workspaceName,
    canManage,
    queues,
    series,
    entries,
    occurrences,
    posts,
    urls,
}: SchedulingProps) {
    const [editingSeries, setEditingSeries] = useState<string | null>(null);
    const [editingQueue, setEditingQueue] = useState<string | null>(null);
    const [category, setCategory] = useState('');
    const categories = [
        ...new Set(queues.map((queue) => queue.category)),
    ].sort();
    const postNames = new Map(posts.map((post) => [post.id, post.label]));
    return (
        <>
            <Head title="Editorial schedules" />
            <main className="mx-auto max-w-6xl space-y-8 px-4 py-6 sm:px-6">
                <header className="space-y-2">
                    <p className="text-sm text-muted-foreground">
                        {workspaceName}
                    </p>
                    <h1 className="text-2xl font-semibold">
                        Editorial schedules
                    </h1>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Create review-safe recurring drafts and arrange category
                        queues by priority. Higher-priority queues fill
                        available{' '}
                        <Link href={urls.slots} className="underline">
                            posting schedule slots
                        </Link>{' '}
                        first. Existing scheduled posts keep their slots.
                    </p>
                </header>
                <section className="space-y-4" aria-label="Recurring series">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold">
                            Recurring series
                        </h2>
                        {canManage && (
                            <Button onClick={() => setEditingSeries('new')}>
                                New series
                            </Button>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Pause skips elapsed dates when resumed. Hold retains the
                        next date for later preparation. Both return scheduled
                        occurrences to drafts; resume does not automatically
                        restore manually scheduled posts. Stop is permanent.
                        Already-publishing posts cannot be recalled.
                    </p>
                    {editingSeries === 'new' && (
                        <SeriesForm
                            workspaceId={workspaceId}
                            url={urls.series}
                            queues={queues}
                            posts={posts}
                            onCancel={() => setEditingSeries(null)}
                        />
                    )}
                    {series.length === 0 && (
                        <p className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                            No recurring series yet. Start with a source post
                            from this company.
                        </p>
                    )}
                    <div className="grid gap-4 lg:grid-cols-2">
                        {series.map((item) => (
                            <article
                                key={item.id}
                                className="space-y-4 rounded-xl border p-4"
                            >
                                <div>
                                    <h3 className="font-semibold">
                                        {item.name}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        Every {item.interval}{' '}
                                        {item.frequency === 'daily'
                                            ? 'day(s)'
                                            : item.frequency === 'weekly'
                                              ? 'week(s)'
                                              : 'month(s)'}{' '}
                                        at {item.local_time} · {item.timezone}
                                    </p>
                                    <p className="text-sm">
                                        {item.state} · {item.generated_count}{' '}
                                        drafts generated · Next:{' '}
                                        {item.next_date}
                                    </p>
                                </div>
                                {item.last_error && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {item.last_error}
                                    </p>
                                )}
                                {canManage && (
                                    <>
                                        <WorkflowControls
                                            key={item.revision}
                                            workspaceId={workspaceId}
                                            state={item.state}
                                            revision={item.revision}
                                            url={item.state_url}
                                        />
                                        {!['stopped', 'completed'].includes(
                                            item.state,
                                        ) && (
                                            <div className="flex gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setEditingSeries(
                                                            item.id,
                                                        )
                                                    }
                                                >
                                                    Edit series
                                                </Button>
                                                {item.state === 'active' && (
                                                    <WorkflowAction
                                                        workspaceId={
                                                            workspaceId
                                                        }
                                                        url={item.generate_url}
                                                    >
                                                        Prepare upcoming drafts
                                                    </WorkflowAction>
                                                )}
                                            </div>
                                        )}
                                    </>
                                )}
                                {editingSeries === item.id && (
                                    <SeriesForm
                                        key={item.revision}
                                        workspaceId={workspaceId}
                                        url={item.update_url}
                                        series={item}
                                        queues={queues}
                                        posts={posts}
                                        onCancel={() => setEditingSeries(null)}
                                    />
                                )}
                                <ul className="space-y-1 text-sm">
                                    {occurrences
                                        .filter(
                                            (occurrence) =>
                                                occurrence.recurring_post_series_id ===
                                                item.id,
                                        )
                                        .slice(0, 5)
                                        .map((occurrence) => (
                                            <li key={occurrence.id}>
                                                {occurrence.occurrence_key}:{' '}
                                                {occurrence.post_url ? (
                                                    <Link
                                                        className="underline"
                                                        href={
                                                            occurrence.post_url
                                                        }
                                                    >
                                                        {occurrence.status ===
                                                        'skipped'
                                                            ? 'Skipped occurrence (kept for reference)'
                                                            : 'Review occurrence draft'}
                                                    </Link>
                                                ) : (
                                                    'Draft removed; occurrence will not be regenerated'
                                                )}
                                            </li>
                                        ))}
                                </ul>
                            </article>
                        ))}
                    </div>
                </section>
                <section className="space-y-4" aria-label="Priority queues">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold">
                            Priority queues
                        </h2>
                        {canManage && (
                            <div className="flex gap-2">
                                <WorkflowAction
                                    workspaceId={workspaceId}
                                    url={urls.fill}
                                >
                                    Fill next open slots
                                </WorkflowAction>
                                <Button onClick={() => setEditingQueue('new')}>
                                    New queue
                                </Button>
                            </div>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Review and publishing checks run before slot assignment.
                        Pause or hold returns scheduled queue items to drafts
                        and retains their order. Stop permanently blocks the
                        queue; remove an item to manage it separately.
                    </p>
                    <label className="flex items-center gap-2 text-sm">
                        Filter category
                        <select
                            className="rounded-md border bg-background p-2"
                            value={category}
                            onChange={(event) =>
                                setCategory(event.target.value)
                            }
                        >
                            <option value="">All categories</option>
                            {categories.map((value) => (
                                <option key={value}>{value}</option>
                            ))}
                        </select>
                    </label>
                    {editingQueue === 'new' && (
                        <QueueForm
                            workspaceId={workspaceId}
                            url={urls.queues}
                            onCancel={() => setEditingQueue(null)}
                        />
                    )}
                    {queues.length === 0 && (
                        <p className="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                            Create a named queue for announcements, educational
                            posts, or another category.
                        </p>
                    )}
                    {queues
                        .filter(
                            (queue) => !category || queue.category === category,
                        )
                        .map((queue) => (
                            <article
                                key={queue.id}
                                className="space-y-4 rounded-xl border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 className="font-semibold">
                                            {queue.name}
                                        </h3>
                                        <p className="text-sm text-muted-foreground">
                                            {queue.category} · Priority{' '}
                                            {queue.priority} · {queue.state}
                                        </p>
                                    </div>
                                    {canManage && queue.state !== 'stopped' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setEditingQueue(queue.id)
                                            }
                                        >
                                            Edit queue
                                        </Button>
                                    )}
                                </div>
                                {canManage && (
                                    <WorkflowControls
                                        key={queue.revision}
                                        workspaceId={workspaceId}
                                        state={queue.state}
                                        revision={queue.revision}
                                        url={queue.state_url}
                                    />
                                )}
                                {editingQueue === queue.id && (
                                    <QueueForm
                                        key={queue.revision}
                                        workspaceId={workspaceId}
                                        url={queue.update_url}
                                        queue={queue}
                                        onCancel={() => setEditingQueue(null)}
                                    />
                                )}
                                {canManage && queue.state !== 'stopped' && (
                                    <EnqueueForm
                                        workspaceId={workspaceId}
                                        queue={queue}
                                        posts={posts}
                                    />
                                )}
                                <ul className="divide-y">
                                    {entries
                                        .filter(
                                            (entry) =>
                                                entry.editorial_queue_id ===
                                                queue.id,
                                        )
                                        .sort((a, b) => b.priority - a.priority)
                                        .map((entry) => (
                                            <li
                                                key={entry.id}
                                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                                            >
                                                <div>
                                                    <Link
                                                        className="text-sm font-medium underline"
                                                        href={entry.post_url}
                                                    >
                                                        {postNames.get(
                                                            entry.post_id,
                                                        ) ?? 'Open post'}
                                                    </Link>
                                                    <p className="text-xs text-muted-foreground">
                                                        Priority{' '}
                                                        {entry.priority} ·{' '}
                                                        {entry.status}
                                                        {entry.scheduled_at
                                                            ? ` · ${new Date(entry.scheduled_at).toLocaleString()}`
                                                            : ''}
                                                    </p>
                                                    {entry.blocked_reason && (
                                                        <p className="text-sm text-amber-700 dark:text-amber-400">
                                                            {
                                                                entry.blocked_reason
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                                {canManage && (
                                                    <WorkflowAction
                                                        workspaceId={
                                                            workspaceId
                                                        }
                                                        url={entry.remove_url}
                                                        method="delete"
                                                    >
                                                        Remove from queue
                                                    </WorkflowAction>
                                                )}
                                            </li>
                                        ))}
                                </ul>
                            </article>
                        ))}
                </section>
            </main>
        </>
    );
}
