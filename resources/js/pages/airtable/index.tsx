import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { InterfaceSwitch } from '@/components/airtable/interface-switch';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index, resolve, sync, update } from '@/routes/airtable';
import { review, show } from '@/routes/posts';
import type { MediaView } from '@/types/compose';

type Integration = {
    enabled: boolean;
    base_id: string;
    table_id: string;
    post_name_field?: string | null;
    interface_url: string | null;
    sync_interval_minutes: number;
    last_synced_at: string | null;
    last_error: string | null;
    token_configured: boolean;
    api_calls: number;
    cooldown_until: string | null;
    lease_until: string | null;
};

type ReviewPost = {
    id: string;
    base_text: string;
    status: string;
    review_status: string;
    revision: string;
    review_note: string | null;
    airtable_record_id: string | null;
    sync_error: string | null;
    conflict: { text: string; revision: string; hash: string } | null;
    targets: {
        id: string;
        platform: string;
        handle: string | null;
        sections: string[];
        format: string;
    }[];
    media: MediaView[];
};

type Props = {
    integration: Integration | null;
    workspaceId: string;
    canManage: boolean;
    selectedPostId: string;
    source: 'dashboard' | 'airtable';
    posts: ReviewPost[];
    audit: {
        id: string;
        post_id: string | null;
        action: string;
        source: string;
        actor_name: string | null;
        created_at: string;
    }[];
};

function formatDate(value: string | null) {
    return value ? new Date(value).toLocaleString() : 'Not synced yet';
}

function ReviewCard({
    post,
    canManage,
    source,
    selected,
}: {
    post: ReviewPost;
    canManage: boolean;
    source: Props['source'];
    selected: boolean;
}) {
    const [checked, setChecked] = useState(false);
    const form = useForm({
        action: 'submit',
        revision: post.revision,
        note: post.review_note ?? '',
        source,
    });
    const resolution = useForm({
        resolution: 'dashboard',
        revision: post.revision,
        conflict_hash: post.conflict?.hash ?? '',
    });
    const canReview = ['draft', 'scheduled', 'failed', 'missed'].includes(
        post.status,
    );
    const act = (action: string) => {
        form.transform((data) => ({ ...data, action }));
        form.post(review.url(post.id), { preserveScroll: true });
    };
    const resolveConflict = (choice: string) => {
        resolution.transform((data) => ({ ...data, resolution: choice }));
        resolution.post(resolve.url(post.id), { preserveScroll: true });
    };

    return (
        <article
            id={`post-${post.id}`}
            className={cn(
                'min-w-0 rounded-2xl border bg-card p-5',
                selected && 'border-primary ring-1 ring-primary',
            )}
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap gap-2">
                    <Badge variant="secondary">{post.status}</Badge>
                    <Badge
                        variant={
                            post.review_status === 'approved'
                                ? 'default'
                                : 'outline'
                        }
                    >
                        {post.review_status.replaceAll('_', ' ')}
                    </Badge>
                </div>
                <Link
                    href={show(post.id)}
                    className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                >
                    Open full composer
                </Link>
            </div>
            <p className="mt-4 line-clamp-4 text-sm whitespace-pre-wrap">
                {post.base_text || 'Media-only post'}
            </p>
            {post.sync_error && (
                <p role="status" className="mt-3 text-sm text-destructive">
                    {post.sync_error}
                </p>
            )}
            <details open={selected || undefined} className="mt-4">
                <summary className="cursor-pointer text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring">
                    Review content, destinations and media
                </summary>
                <div className="mt-4 grid gap-4">
                    {post.targets.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No publishing accounts selected. Choose destinations
                            in the composer before publication.
                        </p>
                    )}
                    {post.targets.map((target) => (
                        <section
                            key={target.id}
                            className="rounded-xl border p-3"
                        >
                            <h3 className="text-sm font-semibold">
                                {target.platform} · {target.handle ?? 'Account'}{' '}
                                · {target.format}
                            </h3>
                            {target.sections.map((text, i) => (
                                <p
                                    key={`${target.id}-${i}`}
                                    className="mt-2 text-sm whitespace-pre-wrap"
                                >
                                    {text}
                                </p>
                            ))}
                        </section>
                    ))}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {post.media.map((media) => (
                            <div key={media.id}>
                                {media.kind === 'video' ? (
                                    <video
                                        controls
                                        preload="metadata"
                                        src={media.url}
                                        className="max-h-48 w-full rounded-lg"
                                    />
                                ) : (
                                    <img
                                        src={media.url}
                                        alt={
                                            media.alt_text ?? 'Post attachment'
                                        }
                                        className="max-h-48 w-full rounded-lg object-contain"
                                        loading="lazy"
                                    />
                                )}
                                {media.alt_text && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {media.alt_text}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        For thread placement and per-account media variants,
                        inspect the full composer before approving.
                    </p>
                    {canReview && (
                        <>
                            <Label htmlFor={`note-${post.id}`}>
                                Review note
                            </Label>
                            <Textarea
                                id={`note-${post.id}`}
                                maxLength={5000}
                                value={form.data.note}
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                            />
                            {canManage && post.review_status === 'pending' && (
                                <label className="flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        onChange={(e) =>
                                            setChecked(e.target.checked)
                                        }
                                        className="mt-1"
                                    />
                                    I reviewed this revision, including its
                                    destinations and attached media
                                </label>
                            )}
                            <div className="flex flex-wrap gap-2">
                                {post.review_status !== 'pending' && (
                                    <Button
                                        variant="outline"
                                        disabled={form.processing}
                                        onClick={() => act('submit')}
                                    >
                                        Submit for review
                                    </Button>
                                )}
                                {canManage &&
                                    post.review_status === 'pending' && (
                                        <Button
                                            disabled={
                                                form.processing || !checked
                                            }
                                            onClick={() => act('approve')}
                                        >
                                            Approve this revision
                                        </Button>
                                    )}
                                {canManage && (
                                    <Button
                                        variant="outline"
                                        disabled={form.processing}
                                        onClick={() => act('request_changes')}
                                    >
                                        Request changes
                                    </Button>
                                )}
                            </div>
                            {!canManage && (
                                <p className="text-xs text-muted-foreground">
                                    Workspace owners and admins can approve or
                                    request changes.
                                </p>
                            )}
                            <p className="text-xs text-muted-foreground">
                                Approval does not publish. Publishing and
                                scheduling use the existing composer. Content or
                                destination changes require a new approval.
                            </p>
                        </>
                    )}
                    <div
                        aria-live="polite"
                        className="text-sm text-destructive"
                    >
                        {Object.values(form.errors).join(' ')}
                    </div>
                </div>
            </details>
            {post.conflict && (
                <div className="mt-4 grid gap-3 rounded-xl border border-amber-500/40 bg-amber-500/5 p-4">
                    <h3 className="font-medium">
                        Airtable proposal needs a decision
                    </h3>
                    <p className="text-sm whitespace-pre-wrap">
                        {post.conflict.text}
                    </p>
                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled={resolution.processing}
                                onClick={() => resolveConflict('dashboard')}
                            >
                                Keep dashboard content
                            </Button>
                            <Button
                                variant="outline"
                                disabled={
                                    resolution.processing ||
                                    post.status !== 'draft'
                                }
                                onClick={() => resolveConflict('airtable')}
                            >
                                Use Airtable proposal
                            </Button>
                        </div>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Applying a proposal resets approval. Threads must be
                        edited in the composer.
                    </p>
                    <p role="status" className="text-sm text-destructive">
                        {Object.values(resolution.errors).join(' ')}
                    </p>
                </div>
            )}
        </article>
    );
}

export default function AirtableWorkspace({
    integration,
    workspaceId,
    canManage,
    selectedPostId,
    source,
    posts,
    audit,
}: Props) {
    const config = useForm({
        enabled: integration?.enabled ?? false,
        base_id: integration?.base_id ?? '',
        table_id: integration?.table_id ?? '',
        post_name_field: integration?.post_name_field ?? '',
        interface_url: integration?.interface_url ?? '',
        sync_interval_minutes: integration?.sync_interval_minutes ?? 0,
    });
    const [syncing, setSyncing] = useState(false);
    const [filter, setFilter] = useState('all');
    const airtableUrl =
        integration?.interface_url ||
        (integration
            ? `https://airtable.com/${integration.base_id}/${integration.table_id}`
            : null);
    const running =
        !!integration?.lease_until &&
        new Date(integration.lease_until).getTime() > Date.now();
    const cooling =
        !!integration?.cooldown_until &&
        new Date(integration.cooldown_until).getTime() > Date.now();
    const visiblePosts = [...posts]
        .sort(
            (a, b) =>
                Number(b.id === selectedPostId) -
                Number(a.id === selectedPostId),
        )
        .filter(
            (post) =>
                filter === 'all' ||
                (filter === 'conflicts'
                    ? !!post.conflict
                    : ['pending', 'stale', 'changes_requested'].includes(
                          post.review_status,
                      )),
        );

    return (
        <>
            <Head title="Airtable workspace" />
            <main className="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 sm:p-6 lg:p-8">
                <header className="flex flex-wrap items-start justify-between gap-5">
                    <div className="max-w-xl">
                        <p className="text-xs font-semibold tracking-widest text-primary uppercase">
                            One workspace · two ways to work
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Dashboard + Airtable
                        </h1>
                        <p className="mt-3 text-sm leading-6 text-muted-foreground">
                            Plan and propose drafts in Airtable. Review the same
                            content here. Both interfaces share one publishing
                            workflow.
                        </p>
                    </div>
                    <div className="w-full sm:w-56">
                        <InterfaceSwitch />
                    </div>
                </header>

                <section className="grid gap-5 rounded-2xl border bg-card p-5 sm:grid-cols-[1fr_auto]">
                    <div className="grid gap-2">
                        <h2 className="font-semibold">
                            {running
                                ? 'Sync in progress'
                                : integration?.enabled
                                  ? 'Parallel access enabled'
                                  : 'Airtable is optional'}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Last successful sync:{' '}
                            {formatDate(integration?.last_synced_at ?? null)}
                        </p>
                        {integration && (
                            <p className="text-xs text-muted-foreground">
                                {integration.sync_interval_minutes === 0
                                    ? 'Manual sync'
                                    : `Every ${integration.sync_interval_minutes / 60} hours`}{' '}
                                · {integration.api_calls} API calls tracked this
                                month
                            </p>
                        )}
                        {integration?.last_error && (
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {integration.last_error}
                            </p>
                        )}
                        {cooling && (
                            <p className="text-xs text-muted-foreground">
                                Next sync available after{' '}
                                {formatDate(
                                    integration?.cooldown_until ?? null,
                                )}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-start gap-2">
                        {airtableUrl && (
                            <a
                                href={airtableUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                {source === 'airtable'
                                    ? 'Return to Airtable ↗'
                                    : 'Open Airtable ↗'}
                            </a>
                        )}
                        {canManage && (
                            <Button
                                disabled={
                                    !integration?.enabled ||
                                    !integration.token_configured ||
                                    syncing ||
                                    running ||
                                    cooling
                                }
                                onClick={() => {
                                    setSyncing(true);
                                    router.post(
                                        sync.url(),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setSyncing(false),
                                        },
                                    );
                                }}
                            >
                                {syncing ? 'Requesting…' : 'Sync now'}
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            onClick={() =>
                                router.reload({
                                    only: ['integration', 'posts', 'audit'],
                                })
                            }
                        >
                            Refresh status
                        </Button>
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-2xl border p-5">
                        <h2 className="font-semibold">
                            1. Draft in either interface
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                            In Airtable, edit Draft text and copy Current
                            revision into Draft revision. New rows need only
                            Draft text. Click Sync now here to apply proposals.
                        </p>
                    </div>
                    <div className="rounded-2xl border p-5">
                        <h2 className="font-semibold">
                            2. Review with your identity
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                            Use the Airtable Review URL or the cards below. Sign
                            in as a workspace owner or admin to approve.
                            Editable status cells are never approval commands.
                        </p>
                    </div>
                    <div className="rounded-2xl border p-5">
                        <h2 className="font-semibold">
                            3. Publish through the same engine
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                            Schedule or publish in the composer. Results return
                            to Airtable on sync. Threads, uploads, DMs and
                            detailed analytics remain in the dashboard.
                        </p>
                        <Link
                            href={dashboard()}
                            className="mt-2 inline-block text-sm text-primary underline"
                        >
                            Open dashboard
                        </Link>
                    </div>
                </section>

                {canManage && (
                    <details
                        className="rounded-2xl border p-5"
                        open={!integration || undefined}
                    >
                        <summary className="cursor-pointer font-semibold">
                            Workspace connection settings
                        </summary>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                config.put(update.url(), {
                                    preserveScroll: true,
                                });
                            }}
                            className="mt-5 grid gap-4"
                        >
                            <p className="text-sm text-muted-foreground">
                                Use a dedicated Posts table with the required
                                fields. Enabling sync shares this workspace’s
                                post content, workflow notes and publishing
                                status with the chosen base. Only give trusted
                                collaborators edit access.
                            </p>
                            <details className="rounded-xl border p-4 text-sm">
                                <summary className="cursor-pointer font-medium">
                                    Required Posts fields
                                </summary>
                                <ul className="mt-3 grid gap-2 text-muted-foreground">
                                    <li>
                                        Single line text: App Post ID, Draft
                                        revision, Current revision, Review
                                        status, Publishing status
                                    </li>
                                    <li>
                                        Long text: Text, Draft text, Review
                                        note, Targets, Sync status
                                    </li>
                                    <li>
                                        Date including time: Scheduled at,
                                        Published at
                                    </li>
                                    <li>URL: Dashboard URL, Review URL</li>
                                </ul>
                                <p className="mt-3 text-muted-foreground">
                                    Keep these names exact. Only Draft text and
                                    Draft revision are editable sync inputs.
                                    Other tables are for planning and are not
                                    synced in this phase.
                                </p>
                            </details>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="base-id">
                                        Airtable base ID
                                    </Label>
                                    <Input
                                        id="base-id"
                                        placeholder="app…"
                                        required
                                        value={config.data.base_id}
                                        onChange={(e) =>
                                            config.setData(
                                                'base_id',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="table-id">
                                        Posts table ID
                                    </Label>
                                    <Input
                                        id="table-id"
                                        placeholder="tbl…"
                                        required
                                        value={config.data.table_id}
                                        onChange={(e) =>
                                            config.setData(
                                                'table_id',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="post-name-field">
                                    Post title field name (optional)
                                </Label>
                                <Input
                                    id="post-name-field"
                                    placeholder="Post name"
                                    maxLength={100}
                                    value={config.data.post_name_field}
                                    onChange={(e) =>
                                        config.setData(
                                            'post_name_field',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Enter the exact name of a single line text
                                    field to name newly exported rows. Existing
                                    Airtable names stay unchanged.
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="interface-url">
                                    Airtable interface URL (optional)
                                </Label>
                                <Input
                                    id="interface-url"
                                    type="url"
                                    placeholder="https://airtable.com/…"
                                    value={config.data.interface_url}
                                    onChange={(e) =>
                                        config.setData(
                                            'interface_url',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="sync-cadence">
                                    Sync cadence
                                </Label>
                                <select
                                    id="sync-cadence"
                                    className="h-10 rounded-lg border bg-background px-3 text-sm"
                                    value={config.data.sync_interval_minutes}
                                    onChange={(e) =>
                                        config.setData(
                                            'sync_interval_minutes',
                                            Number(e.target.value),
                                        )
                                    }
                                >
                                    <option value={0}>
                                        Manual only (recommended for free plans)
                                    </option>
                                    <option value={360}>Every 6 hours</option>
                                    <option value={720}>Every 12 hours</option>
                                    <option value={1440}>Daily</option>
                                </select>
                            </div>
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={config.data.enabled}
                                    onChange={(e) =>
                                        config.setData(
                                            'enabled',
                                            e.target.checked,
                                        )
                                    }
                                    className="mt-1"
                                />
                                Enable two-way draft sync and share workspace
                                posts with this Airtable base
                            </label>
                            <p className="text-sm text-muted-foreground">
                                Server credential:{' '}
                                {integration?.token_configured
                                    ? 'configured'
                                    : 'not configured'}
                                . An operator must add a base-scoped PAT in
                                server secret storage for workspace{' '}
                                {workspaceId}. No token belongs in this form or
                                an Airtable cell.
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Free-plan quotas are shared with other
                                integrations. Manual sync and the local API
                                safety budget reduce usage; they cannot
                                guarantee you stay within Airtable’s total
                                allowance.
                            </p>
                            <p
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {Object.values(config.errors).join(' ')}
                            </p>
                            <Button
                                type="submit"
                                className="w-fit"
                                disabled={config.processing || running}
                            >
                                {config.processing
                                    ? 'Saving…'
                                    : 'Save connection settings'}
                            </Button>
                        </form>
                    </details>
                )}

                <section className="grid gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-xl font-semibold">
                                Shared reviews & proceedings
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Latest 100 posts. Airtable review links always
                                open the requested post.
                            </p>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            Show
                            <select
                                className="rounded-lg border bg-background px-3 py-2"
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                            >
                                <option value="all">All posts</option>
                                <option value="review">Needs review</option>
                                <option value="conflicts">
                                    Sync conflicts
                                </option>
                            </select>
                        </label>
                    </div>
                    {visiblePosts.length === 0 && (
                        <div className="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                            No posts in this view. Create a dashboard draft or
                            add Draft text to a new Airtable row and sync.
                        </div>
                    )}
                    {visiblePosts.map((post) => (
                        <ReviewCard
                            key={`${post.id}-${post.revision}-${post.review_status}-${post.conflict?.hash ?? ''}`}
                            post={post}
                            source={source}
                            canManage={canManage}
                            selected={post.id === selectedPostId}
                        />
                    ))}
                </section>

                <details className="rounded-2xl border p-5">
                    <summary className="cursor-pointer font-semibold">
                        Recent workflow history
                    </summary>
                    <ol className="mt-4 grid gap-3">
                        {audit.map((event) => (
                            <li
                                key={event.id}
                                className="flex flex-wrap justify-between gap-2 border-b pb-3 text-sm"
                            >
                                <span>
                                    {event.action.replaceAll('_', ' ')} ·{' '}
                                    {event.actor_name ?? 'Airtable integration'}{' '}
                                    · {event.source}
                                </span>
                                <time className="text-xs text-muted-foreground">
                                    {formatDate(event.created_at)}
                                </time>
                            </li>
                        ))}
                        {audit.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                Review and sync actions will appear here.
                            </li>
                        )}
                    </ol>
                </details>
                <Link href={index()} className="sr-only">
                    Reset Airtable view
                </Link>
            </main>
        </>
    );
}
