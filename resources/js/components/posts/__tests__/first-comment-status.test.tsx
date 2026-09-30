import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { FirstCommentDelivery, PostView } from '@/types/compose';

import { FirstCommentStatus } from '../first-comment-status';

const { post, reload } = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({ post }),
    router: { reload },
}));

function fixture(status: FirstCommentDelivery['status']): PostView {
    return {
        id: 'post',
        base_text: 'Hello',
        segments: ['Hello'],
        status: 'published',
        published_at: null,
        updated_at: '2026-09-30T00:00:00Z',
        scheduled_at: null,
        auto_repost: null,
        destination: { kind: 'account', id: 'account' },
        media: [],
        targets: [
            {
                id: 'target',
                connected_account_id: 'account',
                platform: 'instagram',
                handle: '@brand',
                display_name: null,
                avatar_url: null,
                sections: ['Hello'],
                content_override: null,
                auto_split: true,
                format: 'feed',
                issues: [],
                status: 'published',
                error_kind: null,
                error_message: null,
                attempts: 1,
                remote_id: 'root',
                first_comment_delivery: {
                    enabled: true,
                    text: 'Comment snapshot',
                    supported: true,
                    reason: null,
                    max_length: 2200,
                    status,
                    attempts: 1,
                    error_message:
                        status === 'uncertain'
                            ? 'Check the live post. Retry is disabled to prevent duplicate comments.'
                            : null,
                    remote_id: status === 'sent' ? 'comment' : null,
                    sent_at: null,
                    next_attempt_at: null,
                    retry_url: '/first-comment/retry',
                },
            },
        ],
    };
}

describe('first-comment status', () => {
    it('does not offer a resend for uncertain provider outcomes', () => {
        render(<FirstCommentStatus post={fixture('uncertain')} />);
        expect(
            screen.getByText('@brand: Delivery unconfirmed'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Retry first comment' }),
        ).not.toBeInTheDocument();
    });
    it('reports sent comments independently of the published root', () => {
        render(<FirstCommentStatus post={fixture('sent')} />);
        expect(screen.getByText('@brand: Sent')).toBeInTheDocument();
        expect(screen.getByText('Comment snapshot')).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
    it('retries only the separate comment endpoint and refreshes its result', async () => {
        post.mockResolvedValue({});
        render(<FirstCommentStatus post={fixture('retryable')} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry first comment' }),
        );
        await waitFor(() =>
            expect(post).toHaveBeenCalledWith('/first-comment/retry'),
        );
        await waitFor(() =>
            expect(reload).toHaveBeenCalledWith({ only: ['post'] }),
        );
    });
});
