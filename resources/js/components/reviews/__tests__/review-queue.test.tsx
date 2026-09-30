import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import ReviewQueue from '@/pages/reviews/index';
import type { ReviewPost, ReviewQueueProps } from '@/types/reviews';

const calls = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), busy: false }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Head: () => null,
        Link: ({
            href,
            children,
            as,
        }: {
            href: string;
            children: ReactNode;
            as?: string;
        }) =>
            as === 'button' ? (
                <button>{children}</button>
            ) : (
                <a href={href}>{children}</a>
            ),
        useForm: (initial: Record<string, unknown>) => {
            const [data, set] = React.useState(initial);
            const transform = React.useRef<
                (value: Record<string, unknown>) => Record<string, unknown>
            >((value) => value);
            return {
                data,
                errors: {},
                processing: calls.busy,
                setData: (key: string, value: unknown) =>
                    set((current) => ({ ...current, [key]: value })),
                transform: (fn: typeof transform.current) => {
                    transform.current = fn;
                },
                post: (url: string) => calls.post(url, transform.current(data)),
                put: (url: string) => calls.put(url, transform.current(data)),
                reset: () => {},
            };
        },
    };
});

const post: ReviewPost = {
    id: 'post-1',
    base_text: 'Launch caption',
    revision: 'a'.repeat(64),
    review_status: 'awaiting_internal',
    publishing_status: 'draft',
    scheduled_at: null,
    client_user_id: 'client-1',
    on_hold: false,
    targets: [
        {
            id: 'target-1',
            platform: 'x',
            handle: '@brand',
            sections: ['X caption'],
            format: 'post',
            placements: [],
            media_by_section: {},
            internal_status: 'pending',
            client_status: 'pending',
        },
        {
            id: 'target-2',
            platform: 'linkedin',
            handle: 'Company',
            sections: ['LinkedIn caption'],
            format: 'post',
            placements: [],
            media_by_section: {},
            internal_status: 'pending',
            client_status: 'pending',
        },
    ],
    media: [],
    history: {
        data: [
            {
                id: 'event-1',
                action: 'submit',
                stage: 'internal',
                note: 'Please review this version',
                actor_name: 'Editor',
                created_at: '2026-09-30T12:00:00Z',
                revision: 'a'.repeat(64),
                review_target_id: null,
            },
        ],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
    },
    urls: {
        act: '/reviews/posts/post-1/actions',
        assign: '/reviews/posts/post-1/client',
        history: '/reviews/posts/post-1/history',
        open: '/reviews?post=post-1',
        edit: '/posts/post-1',
    },
};
const props: ReviewQueueProps = {
    workspaceName: 'Studio',
    workspaceId: 'workspace-1',
    reviewWorkspaces: [],
    mode: 'internal_client',
    isClient: false,
    canManage: true,
    clients: [{ id: 'client-1', name: 'Client reviewer' }],
    posts: {
        data: [post],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
    },
    urls: {
        index: '/reviews',
        configure: '/reviews/workflow',
        logout: '/logout',
        switchWorkspace: '/workspaces/switch',
        members: '/settings/workspace/members',
    },
};

beforeEach(() => {
    calls.post.mockReset();
    calls.put.mockReset();
    calls.busy = false;
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('renders real previews, stages, reviewer controls and revision-specific history', () => {
    render(<ReviewQueue {...props} />);
    expect(screen.getByText('X caption')).toBeInTheDocument();
    expect(screen.getByText('LinkedIn caption')).toBeInTheDocument();
    expect(screen.getByText('Please review this version')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Edit post' })).toHaveAttribute(
        'href',
        '/posts/post-1',
    );
    expect(
        screen.getByRole('button', { name: 'Save reviewer' }),
    ).toBeInTheDocument();
});

it('approves one account with the exact reviewed revision and stage', () => {
    render(<ReviewQueue {...props} />);
    fireEvent.change(screen.getByLabelText('Decision applies to'), {
        target: { value: 'target-2' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Approve internal' }));
    expect(calls.post).toHaveBeenCalledWith(post.urls.act, {
        action: 'approve',
        revision: post.revision,
        stage: 'internal',
        target_id: 'target-2',
        note: '',
    });
});

it('requires feedback for changes and holds and submits the note', () => {
    render(<ReviewQueue {...props} />);
    expect(
        screen.getByRole('button', { name: 'Request changes' }),
    ).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Hold publishing' }),
    ).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Review comment'), {
        target: { value: 'Please revise the closing line' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Request changes' }));
    expect(calls.post).toHaveBeenCalledWith(
        post.urls.act,
        expect.objectContaining({
            action: 'request_changes',
            note: 'Please revise the closing line',
            target_id: null,
        }),
    );
});

it('scopes client controls to client review without editing publishing settings or invitations', () => {
    const clientPost = {
        ...post,
        review_status: 'awaiting_client',
        targets: post.targets.map((target) => ({
            ...target,
            internal_status: 'approved',
        })),
        urls: { ...post.urls, edit: null },
    };
    render(
        <ReviewQueue
            {...props}
            isClient
            canManage={false}
            clients={[]}
            posts={{ ...props.posts, data: [clientPost] }}
            urls={{ ...props.urls, members: null }}
        />,
    );
    expect(
        screen.queryByText('Workspace review policy'),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Submit current revision' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Save reviewer' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Edit post' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Manage reviewers' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Approve client' }));
    expect(calls.post).toHaveBeenCalledWith(
        post.urls.act,
        expect.objectContaining({ stage: 'client', action: 'approve' }),
    );
});

it('blocks duplicate decisions while a request is processing', () => {
    calls.busy = true;
    render(<ReviewQueue {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Approve internal' }));
    fireEvent.click(screen.getByRole('button', { name: 'Approve internal' }));
    expect(calls.post).not.toHaveBeenCalled();
});

it('requires stale revisions to be resubmitted', () => {
    render(
        <ReviewQueue
            {...props}
            posts={{
                ...props.posts,
                data: [{ ...post, review_status: 'stale' }],
            }}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Approve internal' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Submit current revision' }),
    );
    expect(calls.post).toHaveBeenCalledWith(
        post.urls.act,
        expect.objectContaining({ action: 'submit', revision: post.revision }),
    );
});

it('can release a hold without suggesting a publish action', () => {
    render(
        <ReviewQueue
            {...props}
            posts={{
                ...props.posts,
                data: [{ ...post, review_status: 'on_hold', on_hold: true }],
            }}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Approve internal' }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Release hold' }));
    expect(calls.post).toHaveBeenCalledWith(
        post.urls.act,
        expect.objectContaining({ action: 'release_hold' }),
    );
    expect(
        screen.queryByRole('button', { name: /publish now/i }),
    ).not.toBeInTheDocument();
});

it('saves the configured workflow and clears reviewer assignment through their own endpoints', () => {
    render(<ReviewQueue {...props} />);
    fireEvent.change(screen.getByLabelText('Required stages'), {
        target: { value: 'internal' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
    expect(calls.put).toHaveBeenCalledWith(props.urls.configure, {
        mode: 'internal',
        expected_workspace_id: props.workspaceId,
    });
    fireEvent.change(screen.getByLabelText('Client reviewer'), {
        target: { value: '' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save reviewer' }));
    expect(calls.put).toHaveBeenCalledWith(post.urls.assign, {
        client_user_id: null,
        revision: post.revision,
    });
});

it('filters current-page reviews without hiding pagination', () => {
    render(
        <ReviewQueue
            {...props}
            posts={{ ...props.posts, next_page_url: '/reviews?page=2' }}
        />,
    );
    fireEvent.change(screen.getByLabelText('Filter reviews'), {
        target: { value: 'approved' },
    });
    expect(screen.queryByText('Launch caption')).not.toBeInTheDocument();
    expect(screen.getByText('No reviews match this view.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
        'href',
        '/reviews?page=2',
    );
});

it('handles lost history access and leaves retry available', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({ ok: false, status: 403 }),
    );
    render(
        <ReviewQueue
            {...props}
            posts={{
                ...props.posts,
                data: [
                    {
                        ...post,
                        history: {
                            ...post.history,
                            next_page_url:
                                '/reviews/posts/post-1/history?page=2',
                        },
                    },
                ],
            }}
        />,
    );
    fireEvent.click(screen.getByText('Review history and comments'));
    fireEvent.click(
        screen.getByRole('button', { name: 'Load older activity' }),
    );
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent(
            'History could not be loaded',
        ),
    );
    expect(
        screen.getByRole('button', { name: 'Load older activity' }),
    ).toBeEnabled();
});

it('keeps optional mode on the existing whole-post controls', () => {
    render(
        <ReviewQueue
            {...props}
            mode="off"
            posts={{
                ...props.posts,
                data: [{ ...post, review_status: 'pending' }],
            }}
        />,
    );
    expect(
        screen.queryByLabelText('Decision applies to'),
    ).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Review comment')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Approve revision' }));
    expect(calls.post).toHaveBeenCalledWith(
        post.urls.act,
        expect.objectContaining({ action: 'approve', target_id: null }),
    );
});

it('lets a client switch only through the existing authenticated workspace route', () => {
    render(
        <ReviewQueue
            {...props}
            isClient
            canManage={false}
            reviewWorkspaces={[
                { id: 'workspace-1', name: 'Studio' },
                { id: 'workspace-2', name: 'Other workspace' },
            ]}
        />,
    );
    fireEvent.change(screen.getByLabelText('Workspace'), {
        target: { value: 'workspace-2' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Switch workspace' }));
    expect(calls.post).toHaveBeenCalledWith('/workspaces/switch', {
        workspace_id: 'workspace-2',
    });
});

it('shows the exact media-to-section mapping used by publishing', () => {
    render(
        <ReviewQueue
            {...props}
            posts={{
                ...props.posts,
                data: [
                    {
                        ...post,
                        targets: [
                            {
                                ...post.targets[0],
                                media_by_section: { '1': ['media-2'] },
                            },
                        ],
                        media: [
                            {
                                id: 'media-2',
                                url: '/media.jpg',
                                kind: 'image',
                                alt_text: 'Reviewed image',
                                position: 1,
                            },
                        ],
                    },
                ],
            }}
        />,
    );
    expect(screen.getByText('Section 2: Media 2')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Reviewed image' })).toHaveAttribute(
        'src',
        '/media.jpg',
    );
});
