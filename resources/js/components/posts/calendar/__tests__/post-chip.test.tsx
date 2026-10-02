import { DndContext } from '@dnd-kit/core';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { PostChip } from '@/components/posts/calendar/post-chip';
import type { PostRowData } from '@/components/posts/post-row';
import type { PlatformName } from '@/types/compose';

const routerVisit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    router: { visit: routerVisit },
    usePage: () => ({ props: {} }),
}));

function basePost(overrides: Partial<PostRowData> = {}): PostRowData {
    return {
        id: 'post-1',
        base_text: 'Ship the multi-account indicator',
        status: 'scheduled',
        status_label: 'Scheduled',
        author: null,
        target_count: 1,
        updated_at: '2026-06-20T09:00:00Z',
        scheduled_at: '2026-06-20T14:30:00Z',
        published_at: null,
        platforms: ['x'] as PlatformName[],
        targets: [],
        media_count: 0,
        media_preview: null,
        ...overrides,
    };
}

function renderChip(post: PostRowData, draggable = false) {
    return render(
        <DndContext>
            <PostChip post={post} draggable={draggable} />
        </DndContext>,
    );
}

function stack(): HTMLElement {
    return screen.getByLabelText(/accounts?$/);
}

describe('PostChip multi-account indicator', () => {
    it('keeps approved protected schedules clickable for review while disabling direct drag changes', () => {
        renderChip(
            basePost({
                approval: {
                    required: true,
                    status: 'approved',
                } as PostRowData['approval'],
            }),
            true,
        );
        const chip = screen.getByTitle('Ship the multi-account indicator');
        expect(chip).toHaveAttribute('aria-disabled', 'true');
        expect(chip).toHaveClass('cursor-pointer');
        fireEvent.click(chip);
        expect(routerVisit).toHaveBeenCalledWith('/posts/post-1');
    });

    it('still permits direct drag changes when approval is not required', () => {
        renderChip(basePost(), true);
        expect(
            screen.getByTitle('Ship the multi-account indicator'),
        ).toHaveAttribute('aria-disabled', 'false');
        expect(
            screen.getByTitle('Ship the multi-account indicator'),
        ).toHaveClass('cursor-grab');
    });
    it('renders one glyph per platform with no remainder when every target is shown', () => {
        renderChip(
            basePost({
                platforms: ['x', 'bluesky', 'linkedin'],
                target_count: 3,
            }),
        );

        const wrapper = stack();
        expect(wrapper.querySelectorAll('svg')).toHaveLength(3);
        expect(wrapper.textContent).not.toContain('+');
        expect(wrapper).toHaveAttribute('aria-label', '3 accounts');
    });

    it('counts extra accounts on the same platform in the remainder', () => {
        renderChip(basePost({ platforms: ['x'], target_count: 2 }));

        const wrapper = stack();
        expect(wrapper.querySelectorAll('svg')).toHaveLength(1);
        expect(wrapper.textContent).toContain('+1');
        expect(wrapper).toHaveAttribute('aria-label', '2 accounts');
    });

    it('caps the glyphs at three and folds the rest into the remainder', () => {
        renderChip(
            basePost({
                platforms: [
                    'x',
                    'bluesky',
                    'linkedin',
                    'facebook',
                    'instagram',
                ],
                target_count: 5,
            }),
        );

        const wrapper = stack();
        expect(wrapper.querySelectorAll('svg')).toHaveLength(3);
        expect(wrapper.textContent).toContain('+2');
    });

    it('labels a single target in the singular with no remainder', () => {
        renderChip(basePost({ platforms: ['x'], target_count: 1 }));

        const wrapper = stack();
        expect(wrapper.querySelectorAll('svg')).toHaveLength(1);
        expect(wrapper.textContent).not.toContain('+');
        expect(wrapper).toHaveAttribute('aria-label', '1 account');
    });
});
