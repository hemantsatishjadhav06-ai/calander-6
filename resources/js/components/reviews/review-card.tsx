import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import type {
    ReviewEvent,
    ReviewMode,
    ReviewPage,
    ReviewPost,
} from '@/types/reviews';

export const reviewLabel = (value: string) => value.replaceAll('_', ' ');
export const reviewSelectClass =
    'rounded-md border bg-background px-3 py-2 text-sm';

function Errors({ errors }: { errors: Record<string, string> }) {
    return Object.keys(errors).length > 0 ? (
        <p role="alert" className="text-sm text-destructive">
            {Object.values(errors).join(' ')}
        </p>
    ) : null;
}

function ReviewHistory({
    initial,
    revision,
}: {
    initial: ReviewPage<ReviewEvent>;
    revision: string;
}) {
    const [history, setHistory] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const pending = useRef(false);
    const active = useRef<AbortController | null>(null);
    useEffect(() => () => active.current?.abort(), []);

    async function loadMore() {
        if (pending.current || !history.next_page_url) return;
        pending.current = true;
        setBusy(true);
        setError('');
        const controller = new AbortController();
        active.current = controller;
        try {
            const response = await fetch(history.next_page_url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (!response.ok || response.redirected)
                throw new Error(
                    'History could not be loaded. Refresh this page to check your access.',
                );
            const next = (await response.json()) as ReviewPage<ReviewEvent>;
            if (!controller.signal.aborted)
                setHistory((current) => ({
                    ...next,
                    data: [...current.data, ...next.data],
                }));
        } catch (cause) {
            if (!controller.signal.aborted)
                setError(
                    cause instanceof Error
                        ? cause.message
                        : 'History could not be loaded.',
                );
        } finally {
            pending.current = false;
            if (!controller.signal.aborted) setBusy(false);
        }
    }

    return (
        <details className="rounded-lg border p-3">
            <summary className="cursor-pointer text-sm font-medium">
                Review history and comments
            </summary>
            <ol className="mt-3 space-y-3">
                {history.data.map((event) => (
                    <li key={event.id} className="border-l-2 pl-3 text-sm">
                        <p className="font-medium">
                            {event.actor_name ?? 'Former member'} ·{' '}
                            {reviewLabel(event.action)}
                            {event.stage ? ` · ${event.stage}` : ''}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {new Date(event.created_at).toLocaleString()} ·{' '}
                            {event.revision === revision
                                ? 'Current revision'
                                : 'Earlier revision'}
                            {event.review_target_id
                                ? ' · One account'
                                : ' · All accounts'}
                        </p>
                        {event.note && (
                            <p className="mt-1 whitespace-pre-wrap">
                                {event.note}
                            </p>
                        )}
                    </li>
                ))}
            </ol>
            {history.data.length === 0 && (
                <p className="mt-3 text-sm text-muted-foreground">
                    No review activity yet
                </p>
            )}
            {error && (
                <p role="alert" className="mt-2 text-sm text-destructive">
                    {error}
                </p>
            )}
            {history.next_page_url && (
                <Button
                    type="button"
                    variant="outline"
                    className="mt-3"
                    disabled={busy}
                    onClick={() => void loadMore()}
                >
                    {busy ? 'Loading…' : 'Load older activity'}
                </Button>
            )}
        </details>
    );
}

export function ReviewCard({
    post,
    mode,
    isClient,
    canManage,
    clients,
}: {
    post: ReviewPost;
    mode: ReviewMode;
    isClient: boolean;
    canManage: boolean;
    clients: { id: string; name: string }[];
}) {
    const form = useForm({
        action: '',
        revision: post.revision,
        note: '',
        target_id: '',
        stage: isClient ? 'client' : 'internal',
    });
    const assignment = useForm({
        client_user_id: post.client_user_id ?? '',
        revision: post.revision,
    });
    const staged = mode !== 'off';
    const currentStage = isClient ? 'client' : 'internal';
    const canDecide = isClient || canManage;
    const selected = form.data.target_id
        ? post.targets.filter((target) => target.id === form.data.target_id)
        : post.targets;
    const hasPending = selected.some(
        (target) =>
            (isClient ? target.client_status : target.internal_status) ===
            'pending',
    );
    const submitted = !['draft', 'stale', 'not_required'].includes(
        post.review_status,
    );
    const canApprove =
        canDecide &&
        !post.on_hold &&
        (staged ? submitted && hasPending : post.review_status === 'pending');

    function act(action: string) {
        if (form.processing) return;
        form.transform((data) => ({
            ...data,
            action,
            target_id: data.target_id || null,
        }));
        form.post(post.urls.act, {
            preserveScroll: true,
            onSuccess: () => form.reset('note'),
        });
    }

    return (
        <article
            className="space-y-4 rounded-xl border bg-card p-5"
            aria-label={`Review ${post.base_text || 'media post'}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="max-w-2xl font-medium whitespace-pre-wrap">
                        {post.base_text || 'Media post'}
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {reviewLabel(post.publishing_status)}
                        {post.scheduled_at
                            ? ` · ${new Date(post.scheduled_at).toLocaleString()}`
                            : ''}
                    </p>
                </div>
                <Badge variant="outline">
                    {reviewLabel(post.review_status)}
                </Badge>
            </div>
            {post.review_status === 'stale' && (
                <p className="rounded-md bg-muted p-3 text-sm">
                    The content, design, media, destinations or review policy
                    changed. Earlier approvals no longer apply. Submit this
                    revision again.
                </p>
            )}
            {post.on_hold && (
                <p role="status" className="rounded-md bg-muted p-3 text-sm">
                    Publishing is on hold. An internal reviewer must release the
                    hold, then the affected stage needs approval again.
                </p>
            )}
            <div className="grid gap-3 md:grid-cols-2">
                {post.targets.map((target) => (
                    <section
                        key={target.id}
                        className="space-y-2 rounded-lg bg-muted/40 p-3"
                    >
                        <h3 className="text-sm font-medium">
                            {target.platform} ·{' '}
                            {target.handle ?? 'Connected account'} ·{' '}
                            {target.format}
                        </h3>
                        {target.sections.map((section, index) => (
                            <p
                                key={index}
                                className="text-sm whitespace-pre-wrap"
                            >
                                {section}
                            </p>
                        ))}
                        {Object.entries(target.media_by_section).map(
                            ([section, ids]) =>
                                ids.length > 0 && (
                                    <p
                                        key={section}
                                        className="text-xs text-muted-foreground"
                                    >
                                        Section {Number(section) + 1}:{' '}
                                        {ids
                                            .map(
                                                (id) =>
                                                    `Media ${(post.media.find((media) => media.id === id)?.position ?? 0) + 1}`,
                                            )
                                            .join(', ')}
                                    </p>
                                ),
                        )}
                        {staged && (
                            <p className="text-xs text-muted-foreground">
                                Internal: {reviewLabel(target.internal_status)}
                                {mode === 'internal_client'
                                    ? ` · Client: ${reviewLabel(target.client_status)}`
                                    : ''}
                            </p>
                        )}
                    </section>
                ))}
            </div>
            {post.media.length > 0 && (
                <div className="flex gap-3 overflow-x-auto pb-2">
                    {post.media.map((media) => (
                        <figure key={media.id} className="shrink-0">
                            {media.kind === 'video' ? (
                                <video
                                    controls
                                    preload="metadata"
                                    src={media.url}
                                    className="h-48 max-w-xs rounded-md"
                                />
                            ) : (
                                <img
                                    src={media.url}
                                    alt={media.alt_text ?? 'Post media'}
                                    className="h-48 max-w-xs rounded-md object-contain"
                                />
                            )}
                            <figcaption className="mt-1 text-xs text-muted-foreground">
                                Media {media.position + 1}
                            </figcaption>
                        </figure>
                    ))}
                </div>
            )}
            {canManage && mode === 'internal_client' && (
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        assignment.transform((data) => ({
                            ...data,
                            client_user_id: data.client_user_id || null,
                        }));
                        assignment.put(post.urls.assign, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        Client reviewer
                        <select
                            className={reviewSelectClass}
                            value={assignment.data.client_user_id}
                            onChange={(event) =>
                                assignment.setData(
                                    'client_user_id',
                                    event.target.value,
                                )
                            }
                            disabled={assignment.processing}
                        >
                            <option value="">No client assigned</option>
                            {clients.map((client) => (
                                <option key={client.id} value={client.id}>
                                    {client.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={assignment.processing}
                    >
                        Save reviewer
                    </Button>
                    <Errors errors={assignment.errors} />
                    {clients.length === 0 && (
                        <p className="w-full text-xs text-muted-foreground">
                            Add a client through workspace member settings
                            first. Client members only see assigned, internally
                            approved revisions.
                        </p>
                    )}
                </form>
            )}
            {staged && (
                <label className="grid gap-1 text-sm">
                    Comment (
                    {isClient ? 'shared with the team' : 'internal only'})
                    <Textarea
                        aria-label="Review comment"
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                        maxLength={5000}
                        disabled={form.processing}
                    />
                </label>
            )}
            {staged && post.targets.length > 1 && (
                <label className="flex items-center gap-2 text-sm">
                    Decision applies to
                    <select
                        aria-label="Decision applies to"
                        className={reviewSelectClass}
                        value={form.data.target_id}
                        onChange={(event) =>
                            form.setData('target_id', event.target.value)
                        }
                        disabled={form.processing}
                    >
                        <option value="">All accounts</option>
                        {post.targets.map((target) => (
                            <option key={target.id} value={target.id}>
                                {target.platform} ·{' '}
                                {target.handle ?? 'Connected account'}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <div className="flex flex-wrap gap-2">
                {!isClient && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={
                            form.processing ||
                            post.targets.length === 0 ||
                            post.on_hold
                        }
                        onClick={() => act('submit')}
                    >
                        Submit current revision
                    </Button>
                )}
                {canDecide && (
                    <Button
                        type="button"
                        disabled={form.processing || !canApprove}
                        onClick={() => act('approve')}
                    >
                        Approve {staged ? currentStage : 'revision'}
                    </Button>
                )}
                {canDecide && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={
                            form.processing ||
                            post.on_hold ||
                            !submitted ||
                            (staged && !form.data.note.trim())
                        }
                        onClick={() => act('request_changes')}
                    >
                        Request changes
                    </Button>
                )}
                {staged && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing || !form.data.note.trim()}
                        onClick={() => act('comment')}
                    >
                        Add comment
                    </Button>
                )}
                {staged && canDecide && !post.on_hold && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing || !form.data.note.trim()}
                        onClick={() => act('hold')}
                    >
                        Hold publishing
                    </Button>
                )}
                {staged && canManage && post.on_hold && (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => act('release_hold')}
                    >
                        Release hold
                    </Button>
                )}
                {post.urls.edit && (
                    <Link
                        className={buttonVariants({ variant: 'ghost' })}
                        href={post.urls.edit}
                    >
                        Edit post
                    </Link>
                )}
            </div>
            <Errors errors={form.errors} />
            <p className="text-xs text-muted-foreground">
                {staged
                    ? 'All required stages for every account must approve this exact revision before publishing. Any content or design change requires a new review.'
                    : 'Optional reviews preserve the existing whole-post approval workflow.'}
            </p>
            <ReviewHistory
                key={post.history.data[0]?.id ?? post.revision}
                initial={post.history}
                revision={post.revision}
            />
        </article>
    );
}
