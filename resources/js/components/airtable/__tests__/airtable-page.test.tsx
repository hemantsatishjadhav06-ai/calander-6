import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AirtableWorkspace from '@/pages/airtable/index';

const calls = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Head: () => null,
        Link: ({
            href,
            children,
            ...props
        }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
            href: { url: string } | string;
            children: ReactNode;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...props}>
                {children}
            </a>
        ),
        usePage: () => ({ url: '/airtable' }),
        router: { post: calls.post, reload: calls.reload },
        useForm: (initial: Record<string, unknown>) => {
            const [data, set] = React.useState(initial);
            const transform = React.useRef<
                (data: Record<string, unknown>) => Record<string, unknown>
            >((value) => value);
            return {
                data,
                errors: {},
                processing: false,
                setData: (key: string, value: unknown) =>
                    set((current) => ({ ...current, [key]: value })),
                transform: (fn: typeof transform.current) => {
                    transform.current = fn;
                },
                post: (url: string) => calls.post(url, transform.current(data)),
                put: (url: string) => calls.post(url, data),
            };
        },
    };
});

const post = {
    id: 'post-1',
    base_text: 'Launch draft',
    status: 'draft',
    review_status: 'pending',
    revision: 'a'.repeat(64),
    review_note: null,
    airtable_record_id: 'recOne',
    sync_error: null,
    conflict: null,
    targets: [],
    media: [],
};
const integration = {
    enabled: true,
    base_id: 'appTest',
    table_id: 'tblTest',
    interface_url: null,
    sync_interval_minutes: 0,
    last_synced_at: null,
    last_error: null,
    token_configured: true,
    api_calls: 1,
    cooldown_until: null,
    lease_until: null,
};
const props = {
    integration,
    workspaceId: 'workspace-1',
    canManage: true,
    selectedPostId: 'post-1',
    source: 'airtable' as const,
    posts: [post],
    audit: [],
};

beforeEach(() => {
    calls.post.mockReset();
    calls.reload.mockReset();
});
afterEach(cleanup);

it('opens the real Airtable base externally and keeps dashboard access', () => {
    render(<AirtableWorkspace {...props} />);
    const link = screen.getByRole('link', { name: /Return to Airtable/ });
    expect(link.getAttribute('href')).toBe(
        'https://airtable.com/appTest/tblTest',
    );
    expect(link.getAttribute('rel')).toContain('noopener');
    expect(document.querySelector('iframe')).toBeNull();
    expect(screen.getByRole('link', { name: 'Open dashboard' })).toBeTruthy();
});

it('requires review acknowledgment and submits the exact approved revision', () => {
    render(<AirtableWorkspace {...props} />);
    const button = screen.getByRole('button', {
        name: 'Approve this revision',
    });
    expect(button.hasAttribute('disabled')).toBe(true);
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I reviewed this revision/ }),
    );
    fireEvent.click(button);
    expect(calls.post).toHaveBeenCalledWith(
        '/posts/post-1/review',
        expect.objectContaining({
            action: 'approve',
            revision: post.revision,
            source: 'airtable',
        }),
    );
});

it('hides admin actions for members while retaining shared content access', () => {
    render(<AirtableWorkspace {...props} canManage={false} />);
    expect(
        screen.queryByRole('button', { name: 'Approve this revision' }),
    ).toBeNull();
    expect(screen.queryByRole('button', { name: 'Sync now' })).toBeNull();
    expect(screen.getByText('Launch draft')).toBeTruthy();
});

it('disables sync when credentials are missing and never asks for a token in the UI', () => {
    render(
        <AirtableWorkspace
            {...props}
            integration={{ ...integration, token_configured: false }}
        />,
    );
    expect(
        screen
            .getByRole('button', { name: 'Sync now' })
            .hasAttribute('disabled'),
    ).toBe(true);
    expect(document.querySelector('input[type="password"]')).toBeNull();
});

it('keeps conflict resolution explicit and revision-bound', () => {
    const conflict = {
        text: 'Concurrent proposal',
        revision: 'b'.repeat(64),
        hash: 'c'.repeat(64),
    };
    render(<AirtableWorkspace {...props} posts={[{ ...post, conflict }]} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Keep dashboard content' }),
    );
    expect(calls.post).toHaveBeenCalledWith(
        '/airtable/posts/post-1/resolve',
        expect.objectContaining({
            resolution: 'dashboard',
            revision: post.revision,
            conflict_hash: conflict.hash,
        }),
    );
});

it('keeps shared reviews and dashboard access available without an integration', () => {
    render(<AirtableWorkspace {...props} integration={null} />);
    expect(screen.getByText('Airtable is optional')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Open dashboard' })).toBeTruthy();
    expect(
        screen.queryByRole('link', { name: /Return to Airtable/ }),
    ).toBeNull();
    expect(
        screen
            .getByRole('button', { name: 'Sync now' })
            .hasAttribute('disabled'),
    ).toBe(true);
    expect(screen.getByText('Required Posts fields')).toBeTruthy();
});

it('resets approval acknowledgment when a newer revision arrives', () => {
    const view = render(<AirtableWorkspace {...props} />);
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I reviewed this revision/ }),
    );
    expect(
        screen
            .getByRole('button', { name: 'Approve this revision' })
            .hasAttribute('disabled'),
    ).toBe(false);

    view.rerender(
        <AirtableWorkspace
            {...props}
            posts={[{ ...post, revision: 'd'.repeat(64) }]}
        />,
    );

    expect(
        screen
            .getByRole('button', { name: 'Approve this revision' })
            .hasAttribute('disabled'),
    ).toBe(true);
});

it('does not issue overlapping sync requests while a request is pending', () => {
    render(<AirtableWorkspace {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Sync now' }));
    fireEvent.click(screen.getByRole('button', { name: 'Requesting…' }));
    expect(calls.post).toHaveBeenCalledTimes(1);
});

it('shows persistent errors and prevents sync during a server cooldown', () => {
    render(
        <AirtableWorkspace
            {...props}
            integration={{
                ...integration,
                last_error: 'Airtable rate limit reached',
                cooldown_until: '2999-01-01T00:00:00Z',
            }}
        />,
    );
    expect(screen.getByText('Airtable rate limit reached')).toBeTruthy();
    expect(
        screen
            .getByRole('button', { name: 'Sync now' })
            .hasAttribute('disabled'),
    ).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Refresh status' }));
    expect(calls.reload).toHaveBeenCalledWith({
        only: ['integration', 'posts', 'audit'],
    });
});
