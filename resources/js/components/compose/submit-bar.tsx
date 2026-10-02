import { HttpResponseError } from '@inertiajs/core';
import { Link, router, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';

import ComposerController from '@/actions/App/Http/Controllers/Posts/ComposerController';
import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import PostReviewController from '@/actions/App/Http/Controllers/Posts/PostReviewController';
import PostScheduleController from '@/actions/App/Http/Controllers/Posts/PostScheduleController';
import { Send } from '@/components/ui/icons';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { celebrate } from '@/lib/compose/celebrate';
import type { ScheduleTray } from '@/lib/compose/composer-state';
import { type AccountBlock, describeReason } from '@/lib/compose/precheck';
import {
    OPTIMISTIC_PUBLISH,
    OPTIMISTIC_SCHEDULE,
    type OptimisticSubmit,
} from '@/lib/compose/publish-status';
import {
    hasCurrentApproval,
    intendedSchedule,
    reviewErrorData,
    sameSchedule,
} from '@/lib/posts/approval';
import { cn } from '@/lib/utils';
import { index as billingRoute } from '@/routes/billing';
import { publish, queue } from '@/routes/posts';
import type {
    PlatformLimits,
    PlatformName,
    PostView,
    PostApproval,
} from '@/types/compose';

type Props = {
    tray: ScheduleTray;
    postId: string | null;
    disabled?: boolean;
    /** True while a media attachment is still uploading — blocks publishing. */
    uploading?: boolean;
    /** Selected destination accounts that cannot publish until reconnected. */
    attentionHandles?: string[];
    /**
     * Flush the autosave and resolve once the draft (incl. media) is persisted.
     * Awaited before publishing so the publish never races the save that
     * attaches media to the post.
     */
    onSaveDraft: () => Promise<boolean>;
    /** Ensure a persisted post id before publishing; returns the post id. */
    onEnsurePost: () => Promise<string>;
    /** When in queue mode, true if there is no slot to queue into (no schedule, full, loading, or error). */
    queueDisabled?: boolean;
    /**
     * Flip the live status chips to their in-flight state instantly; returns a
     * `revert` to restore the prior snapshot if the request fails.
     */
    onOptimisticSubmit: (optimistic: OptimisticSubmit) => () => void;
    /** Adopt the server's post after a successful publish/queue/schedule. */
    onServerPost: (post: PostView) => void;
    /** Accounts whose content will be rejected by the platform (live). */
    blockedAccounts: AccountBlock[];
    /** Per-platform limits, for rendering block reasons. */
    limits: PlatformLimits[];
    approvalRequired?: boolean;
    approval?: PostApproval | null;
    unsavedChanges?: boolean;
    queueSlot?: string | null;
    onGetServerPost?: () => PostView | null;
    onReviewPost?: (post: PostView) => void;
};

export function hasBlockingIssues(blocked: AccountBlock[]): boolean {
    return blocked.length > 0;
}

function limitsFor(
    limits: PlatformLimits[],
    platform: PlatformName,
): PlatformLimits {
    return (
        limits.find((item) => item.platform === platform) ?? {
            platform,
            maxLength: 0,
            maxBytes: null,
            maxMedia: 0,
            requiresMedia: false,
            maxMediaBytes: 0,
            allowedMime: [],
            threadMax: null,
            maxImageDimensions: { width: 0, height: 0 },
            allowedVideoMime: [],
            maxVideoBytes: 0,
            maxVideoDurationSeconds: 0,
            videoAspectRatioRange: null,
        }
    );
}

// onHttpException's response.data is typed `string` but may arrive already
// parsed at runtime — handle both, mirroring the pattern in
// resources/js/hooks/compose/use-autosave.ts.
function parseServerBlocked(raw: unknown): AccountBlock[] {
    let data: unknown = raw;
    if (typeof raw === 'string') {
        try {
            data = JSON.parse(raw);
        } catch {
            return [];
        }
    }
    if (typeof data !== 'object' || data === null || !('blocked' in data)) {
        return [];
    }
    const blocked = (data as { blocked?: unknown }).blocked;
    if (!Array.isArray(blocked)) {
        return [];
    }

    return blocked.map((item) => ({
        accountId: String(
            (item as { connected_account_id?: string }).connected_account_id ??
                '',
        ),
        handle: String((item as { handle?: string }).handle ?? ''),
        platform: (item as { platform?: unknown }).platform as PlatformName,
        reasons:
            ((item as { issues?: unknown })
                .issues as AccountBlock['reasons']) ?? [],
    }));
}

type ShortcutEvent = Pick<
    KeyboardEvent,
    'altKey' | 'ctrlKey' | 'key' | 'metaKey' | 'shiftKey'
>;

type SubmitGuard = {
    disabled?: boolean;
    uploading: boolean;
    attentionBlocked?: boolean;
    processing: boolean;
    trayMode: ScheduleTray['mode'];
    queueDisabled?: boolean;
};

export function isSubmitShortcut(event: ShortcutEvent): boolean {
    return (
        (event.metaKey || event.ctrlKey) &&
        !event.altKey &&
        !event.shiftKey &&
        event.key === 'Enter'
    );
}

export function shouldAllowSubmit({
    disabled,
    uploading,
    attentionBlocked,
    processing,
    trayMode,
    queueDisabled,
}: SubmitGuard): boolean {
    return !(
        disabled ||
        uploading ||
        attentionBlocked ||
        processing ||
        (trayMode === 'queue' && Boolean(queueDisabled))
    );
}

export function SubmitBar({
    tray,
    postId,
    disabled,
    uploading = false,
    attentionHandles = [],
    onSaveDraft,
    onEnsurePost,
    queueDisabled,
    onOptimisticSubmit,
    onServerPost,
    blockedAccounts,
    limits,
    approvalRequired = false,
    approval = null,
    unsavedChanges = false,
    queueSlot = null,
    onGetServerPost,
    onReviewPost,
}: Props) {
    // useHttp verbs take NO inline data — the body is injected via transform()
    // at submit time so it always reflects the latest reducer state.
    const http = useHttp<
        { scheduled_at?: string | null; revision?: string },
        { post: PostView }
    >({});
    const [noSlot, setNoSlot] = useState(false);
    const [pastTime, setPastTime] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const submittingRef = useRef(false);
    const attentionBlocked = attentionHandles.length > 0;
    // Server-reported blocks (belt-and-suspenders for edge cases the client
    // pre-check missed). Keyed identically to client AccountBlock.
    const [serverBlocked, setServerBlocked] = useState<AccountBlock[]>([]);
    // Only reveal the block list after a submit attempt, so it doesn't nag before.
    const [showBlocked, setShowBlocked] = useState(false);
    const [reviewRequested, setReviewRequested] = useState(false);
    const plannedAt = intendedSchedule(tray, queueSlot);
    const requiresApproval = approval?.required ?? approvalRequired;
    const approved = !unsavedChanges && hasCurrentApproval(approval, plannedAt);
    const awaitingApproval =
        requiresApproval &&
        !unsavedChanges &&
        approval?.status === 'awaiting_approval' &&
        sameSchedule(approval.planned_schedule_at, plannedAt);
    useEffect(() => {
        if (unsavedChanges || approval?.status !== 'awaiting_approval') {
            setReviewRequested(false);
        }
    }, [unsavedChanges, approval?.status, approval?.revision]);

    // Prefer live client blocks; fall back to the last server response.
    const blocks = blockedAccounts.length > 0 ? blockedAccounts : serverBlocked;

    const submitLabel =
        requiresApproval && !approved
            ? awaitingApproval
                ? 'Awaiting approval'
                : 'Request approval'
            : tray.mode === 'now'
              ? 'Publish now'
              : tray.mode === 'queue'
                ? 'Add to queue'
                : 'Schedule';

    async function handleSubmit() {
        if (awaitingApproval) {
            return;
        }
        if (hasBlockingIssues(blockedAccounts)) {
            setShowBlocked(true);
            setServerBlocked([]);

            return;
        }

        if (
            !shouldAllowSubmit({
                disabled,
                uploading,
                attentionBlocked,
                processing: http.processing || submittingRef.current,
                trayMode: tray.mode,
                queueDisabled,
            })
        ) {
            return;
        }

        submittingRef.current = true;
        setSubmitting(true);
        setNoSlot(false);
        setPastTime(false);
        setSubmitError(null);
        setReviewRequested(false);
        const requestReview = requiresApproval && !approved;
        let revert: (() => void) | null = null;
        try {
            // Publishing must wait for a successful save of the current draft.
            if (!(await onSaveDraft())) {
                setSubmitError(
                    'Your draft could not be saved. Resolve any conflict or retry saving before publishing.',
                );

                return;
            }
            const id = postId ?? (await onEnsurePost());
            if (!id) {
                setSubmitError(
                    'Your draft could not be saved. Please try again.',
                );

                return;
            }

            if (requiresApproval) {
                let savedPost = onGetServerPost?.();
                if (!savedPost?.approval) {
                    setSubmitError(
                        'The review status could not be verified. Refresh this draft before continuing.',
                    );

                    return;
                }
                // Saving edits can invalidate the approved revision. A submission
                // that began as a review request must never become a publication.
                if (
                    requestReview ||
                    !hasCurrentApproval(savedPost.approval, plannedAt)
                ) {
                    if (
                        !sameSchedule(
                            savedPost.approval.planned_schedule_at,
                            plannedAt,
                        )
                    ) {
                        const revision = savedPost.approval.revision;
                        http.transform(() => ({
                            scheduled_at: plannedAt,
                            revision,
                        }));
                        const planned = await http.put(
                            PostReviewController.plan(id).url,
                        );
                        savedPost = planned.post;
                        onReviewPost?.(savedPost);
                    }
                    const revision = savedPost.approval?.revision;
                    if (!revision) {
                        setSubmitError(
                            'The saved draft has no review revision. Refresh and try again.',
                        );

                        return;
                    }
                    http.transform(() => ({ revision }));
                    const requested = await http.post(
                        PostReviewController.requestReview(id).url,
                    );
                    onReviewPost?.(requested.post);
                    setReviewRequested(true);

                    return;
                }
            }

            const onSuccess = ({ post }: { post: PostView }) => {
                celebrate();
                onServerPost(post);
                router.visit(ComposerController.show(id).url);
            };

            if (tray.mode === 'now') {
                revert = onOptimisticSubmit(OPTIMISTIC_PUBLISH);
                http.transform(() => ({}));
                await http.post(publish(id).url, { onSuccess });

                return;
            }

            revert = onOptimisticSubmit(OPTIMISTIC_SCHEDULE);
            if (tray.mode === 'queue') {
                http.transform(() =>
                    requiresApproval
                        ? { scheduled_at: plannedAt }
                        : tray.pickedAt
                          ? { scheduled_at: tray.pickedAt }
                          : {},
                );
                await http.post(queue(id).url, { onSuccess });

                return;
            }

            http.transform(() => ({ scheduled_at: tray.pickedAt }));
            await http.put(PostScheduleController.update(id).url, {
                onSuccess,
            });
        } catch (error) {
            revert?.();
            if (error instanceof HttpResponseError) {
                if (
                    requiresApproval &&
                    (error.response.status === 409 ||
                        error.response.status === 422 ||
                        error.response.status === 403)
                ) {
                    const body = reviewErrorData(error.response.data);
                    if (body.post) {
                        onReviewPost?.(body.post);
                    }
                    setSubmitError(
                        body.message ??
                            'This draft needs a fresh review before it can be published.',
                    );

                    return;
                }
                if (error.response.status === 402) {
                    router.visit(billingRoute().url);

                    return;
                }
                // useHttp rejects all HTTP failures, including 422 validation
                // responses, which bypass its onHttpException callback.
                if (error.response.status === 422) {
                    const blocked = parseServerBlocked(error.response.data);
                    if (blocked.length > 0) {
                        setServerBlocked(blocked);
                        setShowBlocked(true);
                    } else if (tray.mode === 'queue') {
                        setNoSlot(true);
                    } else if (tray.mode === 'pick') {
                        setPastTime(true);
                    } else {
                        setSubmitError(
                            'This post could not be published. Check the content and selected accounts, then try again.',
                        );
                    }

                    return;
                }
            }
            setSubmitError(
                'Your post could not be submitted. Please try again.',
            );
        } finally {
            submittingRef.current = false;
            setSubmitting(false);
        }
    }

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            if (
                !isSubmitShortcut(event) ||
                !shouldAllowSubmit({
                    disabled: disabled || awaitingApproval,
                    uploading,
                    attentionBlocked,
                    processing: http.processing || submitting,
                    trayMode: tray.mode,
                    queueDisabled,
                })
            ) {
                return;
            }

            event.preventDefault();
            void handleSubmit();
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    });

    const canSubmit = shouldAllowSubmit({
        disabled: disabled || awaitingApproval,
        uploading,
        attentionBlocked,
        processing: http.processing || submitting,
        trayMode: tray.mode,
        queueDisabled,
    });

    const submitButton = (
        <TrayButton
            variant="primary"
            disabled={!canSubmit}
            onClick={() => void handleSubmit()}
            className="flex-1 sm:flex-none"
        >
            <Send className="size-3.5" aria-hidden="true" />
            <span>{submitLabel}</span>
            <kbd className="ml-0.5 hidden h-4 items-center rounded border border-primary-foreground/25 bg-primary-foreground/15 px-1 font-mono text-[10px] leading-none font-normal text-primary-foreground/90 sm:inline-flex">
                ⌘↵
            </kbd>
        </TrayButton>
    );

    return (
        <div className="flex flex-col items-stretch gap-1.5 sm:items-end sm:justify-self-end">
            <div className="flex items-center gap-1.5">
                <TrayButton
                    onClick={() => void onSaveDraft()}
                    disabled={disabled || submitting}
                    className="flex-1 sm:flex-none"
                >
                    Save draft
                </TrayButton>
                {/* A disabled button emits no hover events, so wrap it in a
                    focusable span that carries the tooltip explaining the block. */}
                {uploading ? (
                    <Tooltip>
                        <TooltipTrigger
                            render={
                                <span
                                    tabIndex={0}
                                    className="flex-1 sm:flex-none"
                                />
                            }
                        >
                            {submitButton}
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            Wait for media to finish uploading.
                        </TooltipContent>
                    </Tooltip>
                ) : (
                    submitButton
                )}
            </div>
            {submitError && (
                <p role="alert" className="text-[12px] text-destructive">
                    {submitError}
                </p>
            )}
            {reviewRequested && (
                <p role="status" className="text-[12px] text-muted-foreground">
                    Saved for review. Nothing has been published or scheduled.
                </p>
            )}
            {noSlot && (
                <p className="text-[12px] text-muted-foreground">
                    No open slot in your posting schedule.{' '}
                    <Link
                        href={PostingScheduleController.show().url}
                        className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
                    >
                        Add slots
                    </Link>
                </p>
            )}
            {pastTime && (
                <p className="text-[12px] text-destructive">
                    That time has already passed — pick a time in the future.
                </p>
            )}
            {showBlocked && blocks.length > 0 && (
                <ul className="space-y-0.5 text-[12px] text-destructive">
                    {blocks.map((block) => (
                        <li key={block.accountId}>
                            <span className="font-medium">{block.handle}</span>
                            {' — '}
                            {block.reasons
                                .map((reason) =>
                                    describeReason(
                                        reason,
                                        block.platform,
                                        limitsFor(limits, block.platform),
                                    ),
                                )
                                .join('; ')}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

type TrayButtonProps = {
    children: ReactNode;
    variant?: 'outline' | 'primary';
    disabled?: boolean;
    onClick?: () => void;
    className?: string;
};

function TrayButton({
    children,
    variant = 'outline',
    disabled = false,
    onClick,
    className,
}: TrayButtonProps) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'inline-flex h-9 items-center justify-center gap-1.5 rounded-md border px-3 text-[12.5px] font-medium transition-[background,border-color,transform,--primary-gradient-top] duration-[120ms] active:scale-[0.985] sm:h-8',
                variant === 'outline' &&
                    'border-border bg-background text-foreground hover:bg-muted disabled:opacity-50',
                variant === 'primary' &&
                    'border-(--primary-gradient-edge) bg-primary-gradient text-primary-foreground shadow-[0_1px_2px_0_rgb(0_0_0/0.04)] inset-shadow-[0_1px_0_0_var(--primary-gradient-highlight)] hover:[--primary-gradient-top:var(--primary-gradient-highlight)] disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
        >
            {children}
        </button>
    );
}
