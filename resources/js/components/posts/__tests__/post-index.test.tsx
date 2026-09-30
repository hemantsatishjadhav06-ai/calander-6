import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import PostsIndex from '@/pages/posts/index';

const calls = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a {...props}>{children}</a>
    ),
    Deferred: ({ children }: { children: ReactNode }) => children,
    InfiniteScroll: ({ children }: { children: ReactNode }) => children,
    usePage: () => ({ scrollProps: {}, props: {} }),
    router: { visit: calls.visit },
}));

type Props = ComponentProps<typeof PostsIndex>;

const props: Props = {
    posts: { data: [] },
    filters: { status: 'all', set: '', platform: '', q: '' },
    sets: [{ id: 'set-1', name: 'Launch team' }],
    counts: { all: 10, draft: 6, scheduled: 2, published: 2, missed: 0 },
};

const platforms = [
    ['x', 'X'],
    ['bluesky', 'Bluesky'],
    ['linkedin', 'LinkedIn'],
    ['facebook', 'Facebook'],
    ['instagram', 'Instagram'],
    ['threads', 'Threads'],
    ['discord', 'Discord'],
];

function expectFilterVisit(filters: Props['filters']) {
    expect(calls.visit).toHaveBeenLastCalledWith('/posts', {
        data: filters,
        only: ['posts', 'filters', 'counts'],
        reset: ['posts'],
        replace: true,
        preserveScroll: false,
    });
}

beforeEach(() => {
    calls.visit.mockReset();
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

it('offers every supported platform in the rendered filter menu', async () => {
    render(<PostsIndex {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Filter' }));

    await screen.findByRole('menuitemcheckbox', { name: 'X' });
    expect(
        screen.getAllByRole('menuitemcheckbox').map((item) => item.textContent),
    ).toEqual(platforms.map(([, label]) => label));
});

it.each(platforms)(
    'selects and deselects %s while retaining other filters and refreshing counts',
    async (platform, label) => {
        const filters = {
            status: 'draft',
            set: 'set-1',
            platform: '',
            q: 'launch',
        };
        const { rerender } = render(
            <PostsIndex {...props} filters={filters} />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Filter/ }));
        fireEvent.click(
            await screen.findByRole('menuitemcheckbox', { name: label }),
        );

        expect(calls.visit).toHaveBeenCalledTimes(1);
        expectFilterVisit({ ...filters, platform });

        rerender(<PostsIndex {...props} filters={{ ...filters, platform }} />);
        expect(
            screen.getByRole('button', {
                name: `Remove ${label} filter`,
                hidden: true,
            }),
        ).toBeInTheDocument();
        const option = screen.getByRole('menuitemcheckbox', { name: label });
        expect(option).toHaveAttribute('aria-checked', 'true');
        fireEvent.click(option);

        expect(calls.visit).toHaveBeenCalledTimes(2);
        expectFilterVisit(filters);
    },
);

it('debounces search and renders refreshed counts from the requested partial response', () => {
    vi.useFakeTimers();
    const { rerender } = render(<PostsIndex {...props} />);
    const input = screen.getByRole('textbox', { name: 'Search posts' });
    expect(
        screen.getByRole('button', { name: /^All\s*10$/ }),
    ).toBeInTheDocument();

    fireEvent.change(input, { target: { value: 'la' } });
    act(() => {
        vi.advanceTimersByTime(200);
    });
    fireEvent.change(input, { target: { value: 'launch' } });
    act(() => {
        vi.advanceTimersByTime(249);
    });
    expect(calls.visit).not.toHaveBeenCalled();
    act(() => {
        vi.advanceTimersByTime(1);
    });
    expect(calls.visit).toHaveBeenCalledTimes(1);
    expectFilterVisit({ ...props.filters, q: 'launch' });

    const response: Props = {
        ...props,
        filters: { ...props.filters, q: 'launch' },
        counts: { all: 3, draft: 1, scheduled: 0, published: 1, missed: 1 },
    };
    // Inertia merges only the requested props into the existing page.
    const requested = calls.visit.mock.calls[0][1].only as (keyof Props)[];
    const partial = Object.fromEntries(
        requested.map((key) => [key, response[key]]),
    );
    rerender(<PostsIndex {...props} {...partial} />);

    for (const name of [
        'All 3',
        'Scheduled 0',
        'Drafts 1',
        'Published 1',
        'Missed 1',
    ]) {
        expect(
            screen.getByRole('button', {
                name: new RegExp(`^${name.replace(' ', '\\s*')}$`),
            }),
        ).toBeInTheDocument();
    }
    expect(screen.queryByRole('button', { name: /^All\s*10$/ })).toBeNull();
});

it('selects a set and all sets with matching count reloads', async () => {
    const filters = { ...props.filters, platform: 'instagram', q: 'launch' };
    const { rerender } = render(<PostsIndex {...props} filters={filters} />);
    fireEvent.click(screen.getByRole('button', { name: /^Filter/ }));
    fireEvent.click(
        await screen.findByRole('menuitemradio', { name: 'Launch team' }),
    );
    expectFilterVisit({ ...filters, set: 'set-1' });

    rerender(<PostsIndex {...props} filters={{ ...filters, set: 'set-1' }} />);
    fireEvent.click(
        await screen.findByRole('menuitemradio', { name: 'All sets' }),
    );
    expectFilterVisit(filters);
});

it.each([
    ['Remove Instagram filter', { platform: '', set: 'set-1' }],
    ['Remove Launch team filter', { platform: 'instagram', set: '' }],
    ['Clear all', { platform: '', set: '' }],
] satisfies [string, Partial<Props['filters']>][])(
    'clears filters using %s and refreshes counts',
    (name, cleared) => {
        const filters = {
            status: 'scheduled',
            set: 'set-1',
            platform: 'instagram',
            q: 'launch',
        };
        render(<PostsIndex {...props} filters={filters} />);
        fireEvent.click(screen.getByRole('button', { name }));
        expectFilterVisit({ ...filters, ...cleared });
    },
);

it('changes status without dropping the search, platform, or set', () => {
    const filters = {
        status: 'all',
        set: 'set-1',
        platform: 'threads',
        q: 'launch',
    };
    render(<PostsIndex {...props} filters={filters} />);
    fireEvent.click(screen.getByRole('button', { name: /^Published\s*2$/ }));
    expectFilterVisit({ ...filters, status: 'published' });
});

it('clears search and cancels its pending request while refreshing counts', () => {
    vi.useFakeTimers();
    render(<PostsIndex {...props} />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Search posts' }), {
        target: { value: 'launch' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Clear search' }));
    act(() => {
        vi.advanceTimersByTime(300);
    });

    expect(calls.visit).toHaveBeenCalledTimes(1);
    expectFilterVisit(props.filters);
    expect(screen.getByRole('textbox', { name: 'Search posts' })).toHaveValue(
        '',
    );
});

it('reflects server filter changes such as back and forward navigation', () => {
    const { rerender } = render(
        <PostsIndex {...props} filters={{ ...props.filters, q: 'launch' }} />,
    );
    rerender(<PostsIndex {...props} />);
    expect(screen.getByRole('textbox', { name: 'Search posts' })).toHaveValue(
        '',
    );
    expect(calls.visit).not.toHaveBeenCalled();
});
