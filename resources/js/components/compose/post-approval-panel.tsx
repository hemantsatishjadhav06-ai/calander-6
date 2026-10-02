import { HttpResponseError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/react';
import { useRef, useState } from 'react';

import PostReviewController from '@/actions/App/Http/Controllers/Posts/PostReviewController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import {
    composerReducer,
    initialComposerState,
} from '@/lib/compose/composer-state';
import { buildPlatformPreview } from '@/lib/compose/platform-preview';
import { toUserTz } from '@/lib/datetime/dayjs';
import {
    approvalStatusMeta,
    reviewErrorData,
    sameSchedule,
} from '@/lib/posts/approval';
import type { Account, PlatformLimits, PostView } from '@/types/compose';

import { PlatformPreviewPanel } from './platform-preview-panel';

type Props = {
    post: PostView | null;
    required: boolean;
    unsavedChanges: boolean;
    plannedAt: string | null;
    accounts: Account[];
    limits: PlatformLimits[];
    onSaveDraft: () => Promise<boolean>;
    onGetServerPost: () => PostView | null;
    onServerPost: (post: PostView) => void;
};

export function reviewPreviews(
    post: PostView,
    accounts: Account[],
    limits: PlatformLimits[],
) {
    const state = composerReducer(initialComposerState(), {
        type: 'hydrate',
        post,
    });

    return post.targets.map((target) => {
        const account = accounts.find(
            (item) => item.id === target.connected_account_id,
        ) ?? {
            id: target.connected_account_id,
            platform: target.platform,
            handle: target.handle ?? '',
            display_name: target.display_name,
            avatar_url: target.avatar_url,
            max_text_length:
                limits.find((limit) => limit.platform === target.platform)
                    ?.maxLength ?? 0,
            x_premium: false,
        };

        return {
            id: target.id,
            preview: buildPlatformPreview({
                account,
                segments: state.overrideByAccount[account.id] ?? state.segments,
                mentions: state.mentions,
                media: state.media,
                excludedMediaIds: new Set<string>(),
                limit: account.max_text_length,
                autoSplit: target.auto_split,
                format: target.format,
                placements:
                    state.placementsByAccount[account.id] ?? state.placements,
                segmentBreaks: state.segmentBreaks,
            }),
        };
    });
}

export function PostApprovalPanel({
    post,
    required,
    unsavedChanges,
    plannedAt,
    accounts,
    limits,
    onSaveDraft,
    onGetServerPost,
    onServerPost,
}: Props) {
    const timezone = useSchedulingTimezone();
    const http = useHttp<
        { revision?: string; reason?: string },
        { post: PostView }
    >({});
    const [snapshot, setSnapshot] = useState<PostView | null>(null);
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const busyRef = useRef(false);
    const approval = post?.approval;
    const enabled = approval?.required ?? required;
    const changed =
        unsavedChanges ||
        !sameSchedule(approval?.planned_schedule_at ?? null, plannedAt);
    const snapshotChanged =
        changed ||
        snapshot?.approval?.revision !== approval?.revision ||
        approval?.status !== 'awaiting_approval';
    const status = changed ? 'draft' : (approval?.status ?? 'draft');
    const meta = approvalStatusMeta[status];

    if (!enabled) {
        return null;
    }

    async function openReview() {
        if (busyRef.current) return;
        busyRef.current = true;
        setBusy(true);
        setError(null);
        try {
            if (!(await onSaveDraft())) {
                setError('Save this draft successfully before reviewing it.');

                return;
            }
            const current = onGetServerPost();
            if (
                current?.approval?.status !== 'awaiting_approval' ||
                !sameSchedule(current.approval.planned_schedule_at, plannedAt)
            ) {
                setError(
                    'The content or publishing time changed. Request approval again to review the latest draft.',
                );

                return;
            }
            setReason('');
            setSnapshot(current);
        } finally {
            busyRef.current = false;
            setBusy(false);
        }
    }

    async function review(action: 'approve' | 'reject' | 'revoke') {
        const reviewed = action === 'revoke' ? post : snapshot;
        if (
            !reviewed?.approval ||
            busyRef.current ||
            (action !== 'revoke' && snapshotChanged)
        )
            return;
        busyRef.current = true;
        setBusy(true);
        setError(null);
        try {
            if (!(await onSaveDraft())) {
                setError('Save this draft successfully before reviewing it.');

                return;
            }
            const current = onGetServerPost();
            if (current?.approval?.revision !== reviewed.approval.revision) {
                setError(
                    'This draft changed after the preview opened. Close the preview and request a fresh review.',
                );

                return;
            }
            http.transform(() => ({
                revision: reviewed.approval?.revision,
                ...(action === 'reject' ? { reason } : {}),
            }));
            const route =
                action === 'approve'
                    ? PostReviewController.approve(reviewed.id)
                    : action === 'reject'
                      ? PostReviewController.reject(reviewed.id)
                      : PostReviewController.revoke(reviewed.id);
            const response = await http.post(route.url);
            onServerPost(response.post);
            setSnapshot(null);
        } catch (failure) {
            if (failure instanceof HttpResponseError) {
                const body = reviewErrorData(failure.response.data);
                if (body.post) onServerPost(body.post);
                setError(
                    body.message ??
                        'The review could not be saved. Refresh the draft and try again.',
                );
            } else {
                setError(
                    'The review could not be saved. Check your connection and try again.',
                );
            }
        } finally {
            busyRef.current = false;
            setBusy(false);
        }
    }

    const reviewedTime = snapshot?.approval?.planned_schedule_at;

    return (
        <section
            aria-label="Post approval"
            className="flex flex-col gap-2 border-b border-border bg-muted/30 px-4 py-3 sm:px-6"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Badge variant={meta.variant}>{meta.label}</Badge>
                {approval?.can_approve && status === 'awaiting_approval' && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() => void openReview()}
                    >
                        Review destinations
                    </Button>
                )}
                {approval?.can_approve && status === 'approved' && (
                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={busy}
                        onClick={() => void review('revoke')}
                    >
                        Revoke approval
                    </Button>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                {status === 'approved'
                    ? 'This revision is approved. Publishing or scheduling is a separate action.'
                    : status === 'awaiting_approval'
                      ? 'The workspace owner must review this draft before it can be published or scheduled.'
                      : changed && approval?.status !== 'draft'
                        ? 'Your changes require fresh approval. Save and request review for the updated content and publishing time.'
                        : 'Save your content and choose a publishing time, then request approval. Drafts remain private.'}
            </p>
            {!changed && approval?.planned_schedule_at && (
                <p className="text-xs text-muted-foreground">
                    Proposed time:{' '}
                    {toUserTz(approval.planned_schedule_at, timezone).format(
                        'MMM D, YYYY [at] h:mm A',
                    )}{' '}
                    ({timezone})
                </p>
            )}
            {!changed && approval?.rejection_reason && (
                <p className="text-xs text-destructive">
                    Changes requested: {approval.rejection_reason}
                </p>
            )}
            {error && !snapshot && (
                <p role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
            <Dialog
                open={snapshot !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) setSnapshot(null);
                }}
            >
                <DialogContent className="flex max-h-[90dvh] flex-col sm:max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>Review this draft</DialogTitle>
                        <DialogDescription>
                            Check every destination below. Approval applies to
                            this saved content and{' '}
                            {reviewedTime
                                ? 'the proposed time'
                                : 'publishing now'}
                            ; it does not publish the post.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 space-y-4 overflow-y-auto">
                        {reviewedTime && (
                            <p className="text-sm font-medium">
                                {toUserTz(reviewedTime, timezone).format(
                                    'MMM D, YYYY [at] h:mm A',
                                )}{' '}
                                ({timezone})
                            </p>
                        )}
                        <div className="grid gap-4 md:grid-cols-2">
                            {snapshot &&
                                reviewPreviews(snapshot, accounts, limits).map(
                                    ({ id, preview }) => (
                                        <PlatformPreviewPanel
                                            key={id}
                                            preview={preview}
                                        />
                                    ),
                                )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="post-review-reason">
                                Feedback when requesting changes (optional)
                            </Label>
                            <Textarea
                                id="post-review-reason"
                                value={reason}
                                maxLength={2000}
                                disabled={busy}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                            />
                        </div>
                    </div>
                    {snapshotChanged && (
                        <p role="alert" className="text-sm text-destructive">
                            This draft or its publishing plan changed. Close
                            this preview and request a fresh review.
                        </p>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setSnapshot(null)}
                        >
                            Close
                        </Button>
                        <Button
                            variant="outline"
                            disabled={busy || snapshotChanged}
                            onClick={() => void review('reject')}
                        >
                            Request changes
                        </Button>
                        <Button
                            disabled={busy || snapshotChanged}
                            onClick={() => void review('approve')}
                        >
                            Approve draft
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
