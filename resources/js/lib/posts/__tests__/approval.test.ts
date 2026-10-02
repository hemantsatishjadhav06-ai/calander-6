import { describe, expect, it } from 'vitest';

import type { PostApproval } from '@/types/compose';

import {
    canPubliclyShare,
    hasCurrentApproval,
    intendedSchedule,
    sameSchedule,
} from '../approval';

function approved(partial: Partial<PostApproval> = {}): PostApproval {
    return {
        required: true,
        status: 'approved',
        revision: 'review-1',
        reviewed_revision: 'review-1',
        requested_at: null,
        approved_at: '2026-10-02T10:00:00Z',
        approved_by: 'owner',
        can_approve: true,
        planned_schedule_at: null,
        rejection_reason: null,
        rejected_at: null,
        rejected_by: null,
        ...partial,
    };
}

describe('reviewed publishing plan', () => {
    it('requires the approved content revision and intended time to match', () => {
        expect(hasCurrentApproval(approved(), null)).toBe(true);
        expect(hasCurrentApproval(approved({ revision: 'edited' }), null)).toBe(
            false,
        );
        expect(hasCurrentApproval(approved(), '2026-10-04T10:00:00Z')).toBe(
            false,
        );
        expect(
            hasCurrentApproval(approved({ status: 'awaiting_approval' }), null),
        ).toBe(false);
    });

    it('compares real instants across timezone offsets and rejects invalid dates', () => {
        expect(
            sameSchedule('2026-10-04T15:30:00+05:30', '2026-10-04T10:00:00Z'),
        ).toBe(true);
        expect(sameSchedule('invalid', 'invalid')).toBe(false);
        expect(sameSchedule(null, '2026-10-04T10:00:00Z')).toBe(false);
    });

    it('uses the displayed queue slot until an explicit slot is picked', () => {
        const nextSlot = '2026-10-04T10:00:00Z';
        expect(
            intendedSchedule({ mode: 'queue', pickedAt: null }, nextSlot),
        ).toBe(nextSlot);
        expect(
            intendedSchedule(
                { mode: 'queue', pickedAt: '2026-10-05T10:00:00Z' },
                nextSlot,
            ),
        ).toBe('2026-10-05T10:00:00Z');
        expect(
            intendedSchedule({ mode: 'now', pickedAt: nextSlot }, nextSlot),
        ).toBeNull();
    });

    it('keeps pending or edited protected drafts out of public sharing', () => {
        expect(canPubliclyShare(approved())).toBe(true);
        expect(
            canPubliclyShare(approved({ status: 'awaiting_approval' })),
        ).toBe(false);
        expect(canPubliclyShare(approved({ revision: 'edited' }))).toBe(false);
        expect(
            canPubliclyShare(approved({ required: false, status: 'draft' })),
        ).toBe(true);
        expect(canPubliclyShare(undefined)).toBe(true);
    });
});
