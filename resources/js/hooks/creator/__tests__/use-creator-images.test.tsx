import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useCreatorImages } from '@/hooks/creator/use-creator-images';
import type { CreatorAssetView } from '@/types/creator';

const fetchMock = vi.fn<typeof fetch>();
const createObjectURL = vi.fn();
const revokeObjectURL = vi.fn();
let failDecode = false;

const asset: CreatorAssetView = {
    id: 'asset-1',
    name: 'Product photo',
    kind: 'image',
    mime: 'image/png',
    width: 800,
    height: 600,
    content_url: '/creator/assets/asset-1/content',
};

class FakeImage {
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    set src(_value: string) {
        queueMicrotask(() => {
            if (failDecode) this.onerror?.();
            else this.onload?.();
        });
    }
}

function imageResponse(type = 'image/png') {
    return {
        ok: true,
        blob: async () => new Blob(['image'], { type }),
    } as Response;
}

beforeEach(() => {
    failDecode = false;
    fetchMock.mockReset();
    createObjectURL.mockReset().mockReturnValue('blob:creator-photo');
    revokeObjectURL.mockReset();
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('Image', FakeImage);
    Object.defineProperty(URL, 'createObjectURL', {
        configurable: true,
        value: createObjectURL,
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
        configurable: true,
        value: revokeObjectURL,
    });
});

afterEach(() => vi.unstubAllGlobals());

describe('Creator authenticated image loading', () => {
    it('deduplicates concurrent fetch/decode work and revokes the object URL on close', async () => {
        fetchMock.mockResolvedValue(imageResponse());
        const { result, unmount } = renderHook(() => useCreatorImages([asset]));
        const first = result.current(asset.id);
        const second = result.current(asset.id);
        expect(first).toBe(second);
        expect(await first).toBeInstanceOf(FakeImage);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][1]).toMatchObject({
            credentials: 'same-origin',
        });
        unmount();
        expect(revokeObjectURL).toHaveBeenCalledExactlyOnceWith(
            'blob:creator-photo',
        );
    });

    it('rejects missing and cross-origin assets without sending an HTTP request', async () => {
        const { result } = renderHook(() =>
            useCreatorImages([
                {
                    ...asset,
                    content_url: 'https://elsewhere.example/photo.png',
                },
            ]),
        );
        await expect(result.current('missing')).rejects.toThrow('missing');
        await expect(result.current(asset.id)).rejects.toThrow(
            'this workspace',
        );
        expect(fetchMock).not.toHaveBeenCalled();
        expect(createObjectURL).not.toHaveBeenCalled();
    });

    it('rejects HTML/SVG content before decoding it', async () => {
        fetchMock.mockResolvedValue(imageResponse('image/svg+xml'));
        const { result } = renderHook(() => useCreatorImages([asset]));
        await expect(result.current(asset.id)).rejects.toThrow(
            'unsupported image format',
        );
        expect(createObjectURL).not.toHaveBeenCalled();
    });

    it('evicts failed requests so a user can retry without losing editing state', async () => {
        fetchMock
            .mockResolvedValueOnce({ ok: false } as Response)
            .mockResolvedValueOnce(imageResponse());
        const { result } = renderHook(() => useCreatorImages([asset]));
        await expect(result.current(asset.id)).rejects.toThrow(
            'Could not load Product photo',
        );
        await expect(result.current(asset.id)).resolves.toBeInstanceOf(
            FakeImage,
        );
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('reloads a changed content URL and does not reuse assets removed after a project switch', async () => {
        fetchMock.mockResolvedValue(imageResponse());
        const { result, rerender } = renderHook(
            ({ assets }) => useCreatorImages(assets),
            { initialProps: { assets: [asset] } },
        );
        await result.current(asset.id);
        rerender({
            assets: [
                {
                    ...asset,
                    content_url: '/creator/assets/asset-1/content?v=2',
                },
            ],
        });
        await result.current(asset.id);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        rerender({ assets: [] });
        await expect(result.current(asset.id)).rejects.toThrow('missing');
    });

    it('releases failed decode blobs instead of accumulating them across retries', async () => {
        failDecode = true;
        fetchMock.mockResolvedValue(imageResponse());
        const { result } = renderHook(() => useCreatorImages([asset]));
        await expect(result.current(asset.id)).rejects.toThrow(
            'Could not decode Product photo',
        );
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:creator-photo');
    });

    it('does not leak a blob when a pending asset request settles after close', async () => {
        let resolveFetch!: (response: Response) => void;
        fetchMock.mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolveFetch = resolve;
                }),
        );
        const { result, unmount } = renderHook(() => useCreatorImages([asset]));
        const pending = result.current(asset.id).catch(() => null);
        unmount();
        resolveFetch(imageResponse());
        await pending;
        expect(
            createObjectURL.mock.calls.length -
                revokeObjectURL.mock.calls.length,
        ).toBe(0);
    });
});
