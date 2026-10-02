import { useHttp } from '@inertiajs/react';
import { act, renderHook } from '@testing-library/react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { OPTIMISTIC_PUBLISH } from '@/lib/compose/publish-status';
import type { PostView } from '@/types/compose';

import { usePublishStatus } from '../use-publish-status';

vi.mock('@inertiajs/react', () => ({ useHttp: vi.fn() }));
vi.mock('sonner', () => ({ toast: { error: vi.fn() } }));

const httpPost = vi.fn();
const post: PostView = {
    id: 'post-1',
    base_text: 'Hello',
    segments: ['Hello'],
    status: 'draft',
    published_at: null,
    updated_at: '2026-10-01T10:00:00Z',
    scheduled_at: null,
    auto_repost: null,
    destination: { kind: 'all', id: null },
    targets: [],
    media: [],
};

beforeEach(() => {
    vi.clearAllMocks();
    httpPost.mockReset();
    vi.mocked(useHttp).mockReturnValue({
        post: httpPost,
    } as unknown as ReturnType<typeof useHttp>);
});

describe('publish status failure recovery', () => {
    it('restores the prior snapshot when an optimistic submission fails before rendering', () => {
        const { result } = renderHook(() =>
            usePublishStatus({ pagePost: post }),
        );

        act(() => {
            const revert = result.current.applyOptimistic(OPTIMISTIC_PUBLISH);
            revert();
        });

        expect(result.current.snapshot).toEqual(post);
    });

    it('keeps status and shows retry feedback when a retry request fails', async () => {
        httpPost.mockRejectedValue(new Error('Offline'));
        const { result } = renderHook(() =>
            usePublishStatus({ pagePost: post }),
        );

        await act(async () => {
            await result.current.retry('target-1');
        });

        expect(result.current.snapshot).toEqual(post);
        expect(result.current.retryingIds.size).toBe(0);
        expect(toast.error).toHaveBeenCalledWith(
            'Could not retry this post. Please try again.',
        );
    });

    it('sends one retry for repeated clicks before React renders', async () => {
        let finishRetry: ((result: { post: PostView }) => void) | undefined;
        httpPost.mockImplementation(
            () =>
                new Promise<{ post: PostView }>((resolve) => {
                    finishRetry = resolve;
                }),
        );
        const { result } = renderHook(() =>
            usePublishStatus({ pagePost: post }),
        );
        let firstRetry: Promise<void> | undefined;
        await act(async () => {
            firstRetry = result.current.retry('target-1');
            await result.current.retry('target-1');
        });

        expect(httpPost).toHaveBeenCalledOnce();
        expect(result.current.retryingIds.has('target-1')).toBe(true);
        await act(async () => {
            finishRetry?.({ post });
            await firstRetry;
        });
        expect(result.current.retryingIds.size).toBe(0);
    });
});
