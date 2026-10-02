import type { ScheduleTray } from '@/lib/compose/composer-state';
import type { PostApproval, PostView } from '@/types/compose';

export const approvalStatusMeta = {
    draft: { label: 'Needs approval', variant: 'secondary' },
    awaiting_approval: { label: 'Awaiting approval', variant: 'warning' },
    approved: { label: 'Approved', variant: 'success' },
    rejected: { label: 'Changes requested', variant: 'destructive' },
} as const;

export function intendedSchedule(
    tray: ScheduleTray,
    queueSlot: string | null = null,
): string | null {
    return tray.mode === 'now'
        ? null
        : (tray.pickedAt ?? (tray.mode === 'queue' ? queueSlot : null));
}

export function sameSchedule(
    left: string | null,
    right: string | null,
): boolean {
    if (left === null || right === null) {
        return left === right;
    }

    return (
        Number.isFinite(Date.parse(left)) &&
        Date.parse(left) === Date.parse(right)
    );
}

export function hasCurrentApproval(
    approval: PostApproval | null | undefined,
    plannedAt: string | null,
): boolean {
    return Boolean(
        approval?.status === 'approved' &&
        approval.reviewed_revision === approval.revision &&
        sameSchedule(approval.planned_schedule_at, plannedAt),
    );
}

export function canPubliclyShare(
    approval: PostApproval | null | undefined,
): boolean {
    return (
        !approval?.required ||
        (approval.status === 'approved' &&
            approval.reviewed_revision === approval.revision)
    );
}

export function reviewErrorData(raw: unknown): {
    message?: string;
    post?: PostView;
} {
    if (typeof raw === 'string') {
        try {
            return reviewErrorData(JSON.parse(raw));
        } catch {
            return {};
        }
    }

    return typeof raw === 'object' && raw !== null ? raw : {};
}
