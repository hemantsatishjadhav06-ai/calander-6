import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useContentMutation } from '../use-content-mutation';

vi.mock('@/lib/csrf', () => ({
    xsrfHeader: () => ({ 'X-XSRF-TOKEN': 'test-token' }),
}));
afterEach(() => vi.unstubAllGlobals());

describe('workspace content mutations', () => {
    it('includes CSRF and same-origin credentials and prevents rapid double submission', async () => {
        let complete!: (value: Response) => void;
        const fetcher = vi.fn(
            (_url: string, _options?: RequestInit) =>
                new Promise<Response>((resolve) => {
                    complete = resolve;
                }),
        );
        vi.stubGlobal('fetch', fetcher);
        const { result } = renderHook(useContentMutation);
        let first!: Promise<unknown>;
        await act(async () => {
            first = result.current.run('/ideas/test/draft', 'POST', {
                expected_revision: 1,
            });
            expect(
                await result.current.run('/ideas/test/draft', 'POST', {
                    expected_revision: 1,
                }),
            ).toBeUndefined();
        });
        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(fetcher.mock.calls[0]?.[1]).toMatchObject({
            credentials: 'same-origin',
            headers: {
                'X-XSRF-TOKEN': 'test-token',
                Accept: 'application/json',
            },
        });
        await act(async () => {
            complete(
                new Response(JSON.stringify({ post_id: 'draft' }), {
                    status: 200,
                }),
            );
            await first;
        });
        expect(result.current.busy).toBe(false);
    });
    it('keeps actionable validation and stale revision errors visible for retry', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            message: 'The idea changed. Reload before saving.',
                        }),
                        { status: 409 },
                    ),
                )
                .mockResolvedValueOnce(
                    new Response(
                        JSON.stringify({
                            errors: { palette: ['Use hex colors.'] },
                        }),
                        { status: 422 },
                    ),
                ),
        );
        const { result } = renderHook(useContentMutation);
        await act(async () => {
            await result.current.run('/brand', 'PUT', {});
        });
        expect(result.current.error).toContain('Reload before saving');
        await act(async () => {
            await result.current.run('/brand', 'PUT', {});
        });
        expect(result.current.error).toBe('Use hex colors.');
        expect(result.current.busy).toBe(false);
    });
    it('cancels pending UI work when the workspace page unmounts', async () => {
        let signal: AbortSignal | undefined;
        vi.stubGlobal(
            'fetch',
            vi.fn((_url: string, options: RequestInit) => {
                signal = options.signal as AbortSignal;
                return new Promise(() => {});
            }),
        );
        const { result, unmount } = renderHook(useContentMutation);
        act(() => {
            void result.current.run('/content/prompt', 'POST', {
                brief: 'hello',
            });
        });
        unmount();
        expect(signal?.aborted).toBe(true);
    });
});
