import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { CreatorApiError, creatorRequest } from '@/lib/creator-api';

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockReset();
    document.cookie = 'XSRF-TOKEN=creator%20csrf';
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; Max-Age=0';
});

describe('Creator HTTP requests', () => {
    it('sends same-origin JSON with session credentials, CSRF and cancellation', async () => {
        const controller = new AbortController();
        fetchMock.mockResolvedValue(
            new Response(JSON.stringify({ project: 'saved' })),
        );
        await expect(
            creatorRequest('/creator/projects', {
                method: 'POST',
                body: { name: 'Design' },
                signal: controller.signal,
            }),
        ).resolves.toEqual({ project: 'saved' });
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBeInstanceOf(URL);
        expect((url as URL).href).toBe(
            `${window.location.origin}/creator/projects`,
        );
        expect(options).toEqual(
            expect.objectContaining({
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                body: '{"name":"Design"}',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': 'creator csrf',
                    'Content-Type': 'application/json',
                },
            }),
        );
    });

    it('lets the browser supply the multipart boundary and preserves every file', async () => {
        const body = new FormData();
        body.append(
            'file',
            new File(['image'], 'photo.png', { type: 'image/png' }),
        );
        fetchMock.mockResolvedValue(new Response('{"asset":{}}'));
        await creatorRequest('/creator/assets', { method: 'POST', body });
        expect(fetchMock.mock.calls[0][1]?.body).toBe(body);
        expect(fetchMock.mock.calls[0][1]?.headers).not.toHaveProperty(
            'Content-Type',
        );
    });

    it.each([
        'https://other.example/creator',
        '//other.example/creator',
        'javascript:alert(1)',
    ])('rejects a cross-origin URL before fetching: %s', async (url) => {
        await expect(creatorRequest(url)).rejects.toMatchObject({
            name: 'CreatorApiError',
            status: 400,
        });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('shows up to three field validation messages and preserves HTTP status', async () => {
        fetchMock.mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Invalid data',
                    errors: {
                        name: ['Name required'],
                        file: ['Wrong type', 'Too large', 'Fourth message'],
                    },
                }),
                { status: 422 },
            ),
        );
        await expect(creatorRequest('/creator/assets')).rejects.toMatchObject({
            message: 'Name required Wrong type Too large',
            status: 422,
        });
    });

    it.each([401, 419])(
        'reports expired sessions for HTTP %s without a JSON message',
        async (status) => {
            fetchMock.mockResolvedValue(new Response('{}', { status }));
            await expect(
                creatorRequest('/creator/assets'),
            ).rejects.toMatchObject({
                status,
                message: expect.stringContaining('session expired'),
            });
        },
    );

    it('rejects a login HTML response even when the redirect returns HTTP 200', async () => {
        fetchMock.mockResolvedValue(new Response('<html>Sign in</html>'));
        await expect(creatorRequest('/creator/assets')).rejects.toBeInstanceOf(
            CreatorApiError,
        );
    });

    it('does not retry or disguise an aborted/failed request', async () => {
        const reason = new DOMException('Aborted', 'AbortError');
        fetchMock.mockRejectedValue(reason);
        await expect(creatorRequest('/creator/projects')).rejects.toBe(reason);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
