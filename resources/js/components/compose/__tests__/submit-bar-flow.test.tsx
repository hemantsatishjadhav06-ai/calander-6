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

import { index as billingRoute } from '@/routes/billing';

import { SubmitBar } from '../submit-bar';

const routerVisit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    useHttp: vi.fn(),
    router: { visit: routerVisit },
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
}));

vi.mock('@/lib/compose/celebrate', () => ({ celebrate: vi.fn() }));

const httpPost = vi.fn();
const httpPut = vi.fn();
const revert = vi.fn();

function renderBar({
    onSaveDraft = vi.fn().mockResolvedValue(true),
    mode = 'now',
}: {
    onSaveDraft?: () => Promise<boolean>;
    mode?: 'now' | 'queue' | 'pick';
} = {}) {
    render(
        <SubmitBar
            tray={{ mode, pickedAt: null }}
            postId="post-1"
            onSaveDraft={onSaveDraft}
            onEnsurePost={vi.fn().mockResolvedValue('post-1')}
            onOptimisticSubmit={() => revert}
            onServerPost={vi.fn()}
            blockedAccounts={[]}
            limits={[]}
        />,
    );

    return onSaveDraft;
}

beforeEach(() => {
    vi.clearAllMocks();
    httpPost.mockResolvedValue({});
    httpPut.mockResolvedValue({});
    vi.mocked(useHttp).mockReturnValue({
        transform: vi.fn(),
        post: httpPost,
        put: httpPut,
        processing: false,
    } as unknown as ReturnType<typeof useHttp>);
});

describe('SubmitBar persistence and failures', () => {
    it('stops publishing when saving the draft fails', async () => {
        renderBar({ onSaveDraft: vi.fn().mockResolvedValue(false) });
        fireEvent.click(screen.getByRole('button', { name: /publish now/i }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'draft could not be saved',
        );
        expect(httpPost).not.toHaveBeenCalled();
        expect(httpPut).not.toHaveBeenCalled();
    });

    it('blocks repeated clicks and keyboard submission while the draft is saving', async () => {
        let finishSave: ((saved: boolean) => void) | undefined;
        const onSaveDraft = vi.fn(
            () =>
                new Promise<boolean>((resolve) => {
                    finishSave = resolve;
                }),
        );
        renderBar({ onSaveDraft });
        const button = screen.getByRole('button', { name: /publish now/i });
        fireEvent.click(button);
        fireEvent.click(button);
        fireEvent.keyDown(document, { key: 'Enter', ctrlKey: true });

        expect(onSaveDraft).toHaveBeenCalledOnce();
        expect(button).toBeDisabled();
        expect(httpPost).not.toHaveBeenCalled();

        await act(async () => finishSave?.(true));
        expect(httpPost).toHaveBeenCalledOnce();
    });

    it('restores status and shows feedback for a failed publishing request', async () => {
        httpPost.mockRejectedValue(new Error('Network unavailable'));
        renderBar();
        fireEvent.click(screen.getByRole('button', { name: /publish now/i }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'could not be submitted',
        );
        expect(revert).toHaveBeenCalledOnce();
        expect(
            screen.getByRole('button', { name: /publish now/i }),
        ).toBeEnabled();
    });

    it('shows server platform blocks from useHttp 422 rejections', async () => {
        httpPost.mockRejectedValue(
            new HttpResponseError('Blocked', {
                status: 422,
                headers: {},
                data: JSON.stringify({
                    blocked: [
                        {
                            connected_account_id: 'account-1',
                            handle: '@example',
                            platform: 'x',
                            issues: ['section_too_long'],
                        },
                    ],
                }),
            }),
        );
        renderBar();
        fireEvent.click(screen.getByRole('button', { name: /publish now/i }));

        expect(await screen.findByText('@example')).toBeInTheDocument();
        expect(revert).toHaveBeenCalledOnce();
    });

    it('shows a missing queue slot from a 422 rejection', async () => {
        httpPost.mockRejectedValue(
            new HttpResponseError('No slot', {
                status: 422,
                headers: {},
                data: JSON.stringify({ message: 'No slot' }),
            }),
        );
        renderBar({ mode: 'queue' });
        fireEvent.click(screen.getByRole('button', { name: /add to queue/i }));

        expect(await screen.findByText(/No open slot/)).toBeInTheDocument();
    });

    it('opens billing when the server requires a subscription', async () => {
        httpPost.mockRejectedValue(
            new HttpResponseError('Subscription required', {
                status: 402,
                headers: {},
                data: '{}',
            }),
        );
        renderBar();
        fireEvent.click(screen.getByRole('button', { name: /publish now/i }));

        await waitFor(() =>
            expect(routerVisit).toHaveBeenCalledWith(billingRoute().url),
        );
        expect(revert).toHaveBeenCalledOnce();
    });
});
