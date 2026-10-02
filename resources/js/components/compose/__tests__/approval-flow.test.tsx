import { HttpResponseError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PostApproval, PostView } from '@/types/compose';

import { SubmitBar } from '../submit-bar';

vi.mock('@inertiajs/react', () => ({
    useHttp: vi.fn(),
    router: { visit: vi.fn() },
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
}));
vi.mock('@/lib/compose/celebrate', () => ({ celebrate: vi.fn() }));

const httpPost = vi.fn();
const httpPut = vi.fn();
const transform = vi.fn();
const optimistic = vi.fn();
const adopt = vi.fn();

function approval(partial: Partial<PostApproval> = {}): PostApproval {
    return {
        required: true,
        status: 'draft',
        revision: 'revision-1',
        reviewed_revision: null,
        requested_at: null,
        approved_at: null,
        approved_by: null,
        can_approve: true,
        planned_schedule_at: null,
        rejection_reason: null,
        rejected_at: null,
        rejected_by: null,
        ...partial,
    };
}

function post(review = approval()): PostView {
    return {
        id: 'post-1',
        base_text: 'Hello',
        segments: ['Hello'],
        status: 'draft',
        published_at: null,
        updated_at: '2026-10-02T10:00:00Z',
        scheduled_at: null,
        auto_repost: null,
        destination: { kind: 'all', id: null },
        targets: [],
        media: [],
        approval: review,
    };
}

function renderBar({
    saved = post(),
    mode = 'now',
    pickedAt = null,
    queueSlot = null,
    flush = vi.fn().mockResolvedValue(true),
    getPost = () => saved,
}: {
    saved?: PostView;
    mode?: 'now' | 'pick' | 'queue';
    pickedAt?: string | null;
    queueSlot?: string | null;
    flush?: () => Promise<boolean>;
    getPost?: () => PostView;
} = {}) {
    render(
        <SubmitBar
            tray={{ mode, pickedAt }}
            postId="post-1"
            onSaveDraft={flush}
            onEnsurePost={vi.fn().mockResolvedValue('post-1')}
            onOptimisticSubmit={optimistic}
            onServerPost={vi.fn()}
            blockedAccounts={[]}
            limits={[]}
            approval={saved.approval}
            onGetServerPost={getPost}
            onReviewPost={adopt}
            queueSlot={queueSlot}
        />,
    );
}

beforeEach(() => {
    vi.clearAllMocks();
    httpPost.mockResolvedValue({
        post: post(approval({ status: 'awaiting_approval' })),
    });
    httpPut.mockResolvedValue({ post: post() });
    vi.mocked(useHttp).mockReturnValue({
        transform,
        post: httpPost,
        put: httpPut,
        processing: false,
    } as unknown as ReturnType<typeof useHttp>);
});

describe('dashboard approval submission', () => {
    it('waits for saving and requests the latest saved revision without publishing', async () => {
        let finishSave: ((saved: boolean) => void) | undefined;
        let latest = post();
        renderBar({
            getPost: () => latest,
            flush: () =>
                new Promise<boolean>((resolve) => {
                    finishSave = resolve;
                }),
        });
        fireEvent.click(
            screen.getByRole('button', { name: /request approval/i }),
        );
        expect(httpPost).not.toHaveBeenCalled();
        latest = post(approval({ revision: 'latest-revision' }));
        await act(async () => finishSave?.(true));
        expect(httpPost).toHaveBeenCalledWith('/posts/post-1/review/request');
        expect(transform.mock.calls.at(-1)?.[0]()).toEqual({
            revision: 'latest-revision',
        });
        expect(optimistic).not.toHaveBeenCalled();
        expect(await screen.findByRole('status')).toHaveTextContent(
            'Nothing has been published or scheduled',
        );
    });

    it('stores the chosen time privately before requesting review of that plan', async () => {
        const pickedAt = '2026-10-05T09:30:00Z';
        httpPut.mockResolvedValue({
            post: post(
                approval({
                    revision: 'planned-revision',
                    planned_schedule_at: pickedAt,
                }),
            ),
        });
        renderBar({ mode: 'pick', pickedAt });
        fireEvent.click(
            screen.getByRole('button', { name: /request approval/i }),
        );
        await waitFor(() => expect(httpPost).toHaveBeenCalled());
        expect(httpPut).toHaveBeenCalledWith('/posts/post-1/review/plan');
        expect(transform.mock.calls[0][0]()).toMatchObject({
            scheduled_at: pickedAt,
            revision: 'revision-1',
        });
        expect(transform.mock.calls[1][0]()).toEqual({
            revision: 'planned-revision',
        });
        expect(httpPost).toHaveBeenCalledWith('/posts/post-1/review/request');
        expect(optimistic).not.toHaveBeenCalled();
    });

    it('pins the displayed queue slot into the reviewed plan', async () => {
        const queueSlot = '2026-10-05T09:30:00Z';
        httpPut.mockResolvedValue({
            post: post(
                approval({
                    revision: 'queue-revision',
                    planned_schedule_at: queueSlot,
                }),
            ),
        });
        renderBar({ mode: 'queue', queueSlot });
        fireEvent.click(
            screen.getByRole('button', { name: /request approval/i }),
        );
        await waitFor(() => expect(httpPost).toHaveBeenCalled());
        expect(transform.mock.calls[0][0]()).toMatchObject({
            scheduled_at: queueSlot,
        });
        expect(httpPost).toHaveBeenCalledWith('/posts/post-1/review/request');
    });

    it('requires fresh review if saving invalidates an approved revision', async () => {
        const approved = post(
            approval({ status: 'approved', reviewed_revision: 'revision-1' }),
        );
        renderBar({
            saved: approved,
            getPost: () => post(approval({ revision: 'edited-revision' })),
        });
        fireEvent.click(screen.getByRole('button', { name: /publish now/i }));
        await waitFor(() => expect(httpPost).toHaveBeenCalled());
        expect(httpPost).toHaveBeenCalledWith('/posts/post-1/review/request');
        expect(optimistic).not.toHaveBeenCalled();
    });

    it('keeps an awaiting draft from publishing through clicks or keyboard shortcuts', () => {
        renderBar({ saved: post(approval({ status: 'awaiting_approval' })) });
        const button = screen.getByRole('button', {
            name: /awaiting approval/i,
        });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        fireEvent.keyDown(document, { key: 'Enter', ctrlKey: true });
        expect(httpPost).not.toHaveBeenCalled();
        expect(optimistic).not.toHaveBeenCalled();
    });

    it('surfaces stale-review feedback and adopts the server review state', async () => {
        const updated = post(approval({ revision: 'other-edit' }));
        httpPost.mockRejectedValue(
            new HttpResponseError('Stale', {
                status: 409,
                headers: {},
                data: JSON.stringify({
                    message: 'Review the latest draft.',
                    post: updated,
                }),
            }),
        );
        renderBar();
        fireEvent.click(
            screen.getByRole('button', { name: /request approval/i }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Review the latest draft.',
        );
        expect(adopt).toHaveBeenCalledWith(updated);
        expect(optimistic).not.toHaveBeenCalled();
    });
});
