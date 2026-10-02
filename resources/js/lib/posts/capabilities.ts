import type { PostView } from '@/types/compose';

export interface PostCapabilities {
    canEdit: boolean;
    canSchedule: boolean;
    canReschedule: boolean;
    canUnschedule: boolean;
    canDelete: boolean;
    canRetry: boolean;
    canDuplicate: boolean;
}

const NONE: PostCapabilities = {
    canEdit: false,
    canSchedule: false,
    canReschedule: false,
    canUnschedule: false,
    canDelete: false,
    canRetry: false,
    canDuplicate: false,
};

export function postCapabilities(post: PostView): PostCapabilities {
    const reviewRequired = post.approval?.required ?? false;
    const approved =
        post.approval?.status === 'approved' &&
        post.approval.reviewed_revision === post.approval.revision;
    // Tolerate partial Inertia payloads that omit targets (e.g. lighter feed rows).
    const hasFailedTarget = (post.targets ?? []).some(
        (t) => t.status === 'failed',
    );
    switch (post.status) {
        case 'draft':
            return {
                ...NONE,
                canEdit: true,
                canSchedule: !reviewRequired,
                canDelete: true,
            };
        case 'scheduled':
            // Not a draft → content is read-only; only the schedule itself can
            // still be changed (reschedule/unschedule) or the post discarded.
            return {
                ...NONE,
                canReschedule: !reviewRequired,
                canUnschedule: true,
                canDelete: true,
            };
        case 'missed':
            // A post the scheduler skipped as too stale: let the user reschedule
            // it back into the pipeline (→ scheduled) or discard it.
            return {
                ...NONE,
                canReschedule: !reviewRequired,
                canDelete: true,
                canDuplicate: true,
            };
        case 'published':
        case 'partial':
        case 'failed':
            return {
                ...NONE,
                canDelete: true,
                canRetry: hasFailedTarget && (!reviewRequired || approved),
                canDuplicate: true,
            };
        default:
            return NONE;
    }
}
