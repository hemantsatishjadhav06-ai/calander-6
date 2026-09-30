import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

import {
    ReviewCard,
    reviewLabel,
    reviewSelectClass,
} from '@/components/reviews/review-card';
import { Button, buttonVariants } from '@/components/ui/button';
import type { ReviewMode, ReviewQueueProps } from '@/types/reviews';

export default function ReviewQueue({
    workspaceName,
    workspaceId,
    reviewWorkspaces,
    mode,
    isClient,
    canManage,
    clients,
    posts,
    urls,
}: ReviewQueueProps) {
    const config = useForm({ mode, expected_workspace_id: workspaceId });
    const workspace = useForm({ workspace_id: workspaceId });
    const [filter, setFilter] = useState('all');
    const visible = posts.data.filter(
        (post) =>
            filter === 'all' ||
            (filter === 'needs_review'
                ? post.review_status !== 'approved'
                : post.review_status === filter),
    );
    return (
        <main className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-8">
            <Head title="Review queue" />
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">
                        {isClient ? 'Client review' : 'Review queue'}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {workspaceName} ·{' '}
                        {isClient
                            ? 'Review the current versions assigned to you'
                            : 'Internal approvals, client feedback and publishing holds'}
                    </p>
                </div>
                {isClient ? (
                    <Link
                        href={urls.logout}
                        method="post"
                        as="button"
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        Sign out
                    </Link>
                ) : (
                    urls.members && (
                        <Link
                            href={urls.members}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            Manage reviewers
                        </Link>
                    )
                )}
            </header>
            {isClient && reviewWorkspaces.length > 1 && (
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        workspace.post(urls.switchWorkspace);
                    }}
                >
                    <label className="flex items-center gap-2 text-sm">
                        Workspace
                        <select
                            className={reviewSelectClass}
                            value={workspace.data.workspace_id}
                            onChange={(event) =>
                                workspace.setData(
                                    'workspace_id',
                                    event.target.value,
                                )
                            }
                            disabled={workspace.processing}
                        >
                            {reviewWorkspaces.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={
                            workspace.processing ||
                            workspace.data.workspace_id === workspaceId
                        }
                    >
                        Switch workspace
                    </Button>
                    {workspace.errors.workspace_id && (
                        <p role="alert" className="text-sm text-destructive">
                            {workspace.errors.workspace_id}
                        </p>
                    )}
                </form>
            )}
            {canManage && (
                <form
                    className="space-y-3 rounded-xl border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        config.put(urls.configure, { preserveScroll: true });
                    }}
                >
                    <h2 className="font-medium">Workspace review policy</h2>
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 text-sm">
                            Required stages
                            <select
                                aria-label="Required stages"
                                className={reviewSelectClass}
                                value={config.data.mode}
                                disabled={config.processing}
                                onChange={(event) =>
                                    config.setData(
                                        'mode',
                                        event.target.value as ReviewMode,
                                    )
                                }
                            >
                                <option value="off">
                                    Optional (existing whole-post reviews)
                                </option>
                                <option value="internal">
                                    Internal approval
                                </option>
                                <option value="internal_client">
                                    Internal, then client approval
                                </option>
                            </select>
                        </label>
                        <Button
                            type="submit"
                            disabled={
                                config.processing || config.data.mode === mode
                            }
                        >
                            Save policy
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Applies to existing unpublished posts. Changing required
                        stages invalidates staged approvals. Optional mode
                        preserves individually requested whole-post reviews.
                    </p>
                    {Object.keys(config.errors).length > 0 && (
                        <p role="alert" className="text-sm text-destructive">
                            {Object.values(config.errors).join(' ')}
                        </p>
                    )}
                </form>
            )}
            <section
                aria-label="Review dashboard"
                className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted/40 p-4"
            >
                <p className="text-sm">
                    On this page:{' '}
                    {
                        posts.data.filter(
                            (post) => post.review_status === 'approved',
                        ).length
                    }{' '}
                    approved ·{' '}
                    {posts.data.filter((post) => post.on_hold).length} on hold ·{' '}
                    {
                        posts.data.filter(
                            (post) =>
                                post.review_status === 'changes_requested',
                        ).length
                    }{' '}
                    need changes
                </p>
                <label className="flex items-center gap-2 text-sm">
                    Show on this page
                    <select
                        aria-label="Filter reviews"
                        className={reviewSelectClass}
                        value={filter}
                        onChange={(event) => setFilter(event.target.value)}
                    >
                        <option value="all">All</option>
                        <option value="needs_review">Needs attention</option>
                        {[
                            'awaiting_internal',
                            'awaiting_client',
                            'changes_requested',
                            'on_hold',
                            'stale',
                            'approved',
                        ].map((status) => (
                            <option key={status} value={status}>
                                {reviewLabel(status)}
                            </option>
                        ))}
                    </select>
                </label>
            </section>
            {visible.length === 0 && (
                <p className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                    {isClient
                        ? 'No current revisions are waiting for you on this page. New content appears after internal approval.'
                        : 'No reviews match this view.'}
                </p>
            )}
            {visible.map((post) => (
                <ReviewCard
                    key={`${post.id}-${post.revision}-${post.client_user_id}-${post.review_status}`}
                    post={post}
                    mode={mode}
                    isClient={isClient}
                    canManage={canManage}
                    clients={clients}
                />
            ))}
            <nav
                aria-label="Review pages"
                className="flex items-center justify-between text-sm"
            >
                <span>
                    Page {posts.current_page} of {posts.last_page}
                </span>
                <div className="flex gap-2">
                    {posts.prev_page_url && (
                        <Link
                            href={posts.prev_page_url}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            Previous
                        </Link>
                    )}
                    {posts.next_page_url && (
                        <Link
                            href={posts.next_page_url}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            Next
                        </Link>
                    )}
                </div>
            </nav>
        </main>
    );
}
