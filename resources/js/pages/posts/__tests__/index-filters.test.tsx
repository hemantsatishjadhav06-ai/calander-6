import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PostsIndex from '../index';

const routerVisit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Deferred: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    InfiniteScroll: ({ children }: { children: React.ReactNode }) => (
        <>{children}</>
    ),
    Link: ({ children, ...props }: React.ComponentProps<'a'>) => (
        <a {...props}>{children}</a>
    ),
    router: { visit: routerVisit },
    usePage: () => ({ scrollProps: {} }),
}));

function renderPosts() {
    return render(
        <PostsIndex
            posts={{ data: [] }}
            filters={{ status: 'all', set: '', platform: '', q: '' }}
            sets={[]}
            counts={{ all: 0, scheduled: 0, draft: 0, published: 0, missed: 0 }}
        />,
    );
}

beforeEach(() => vi.clearAllMocks());
afterEach(() => vi.useRealTimers());

describe('Posts filters', () => {
    it('keeps the current search when changing status and refreshes matching counts', () => {
        renderPosts();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search posts' }),
            { target: { value: 'launch' } },
        );
        fireEvent.click(screen.getByRole('button', { name: /Scheduled/ }));

        expect(routerVisit).toHaveBeenCalledWith(
            '/posts',
            expect.objectContaining({
                data: {
                    status: 'scheduled',
                    set: '',
                    platform: '',
                    q: 'launch',
                },
                only: ['posts', 'filters', 'counts'],
            }),
        );
    });

    it('does not navigate back to posts when the pending search outlives the page', () => {
        vi.useFakeTimers();
        const view = renderPosts();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search posts' }),
            { target: { value: 'launch' } },
        );
        view.unmount();

        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(routerVisit).not.toHaveBeenCalled();
    });

    it('offers filters for every supported publishing platform', async () => {
        renderPosts();
        fireEvent.click(screen.getByRole('button', { name: 'Filter' }));

        for (const platform of [
            'X',
            'Bluesky',
            'LinkedIn',
            'Facebook',
            'Instagram',
            'Threads',
            'Discord',
        ]) {
            expect(
                await screen.findByRole('menuitemcheckbox', { name: platform }),
            ).toBeInTheDocument();
        }
    });
});
