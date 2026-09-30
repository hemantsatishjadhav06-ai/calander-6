import { afterEach, describe, expect, it, vi } from 'vitest';

import { creatorZip, encodeCreatorCanvas } from '@/lib/creator-export';

function canvasWith(blobs: (Blob | null)[]) {
    const toBlob = vi.fn((callback: BlobCallback) =>
        callback(blobs.shift() ?? null),
    );
    return {
        width: 1080,
        height: 1350,
        toBlob,
    } as unknown as Omit<HTMLCanvasElement, 'toBlob'> & {
        toBlob: typeof toBlob;
    };
}

afterEach(() => vi.unstubAllGlobals());

describe('Creator canvas encoding', () => {
    it('keeps PNG dimensions and encodes the existing all-layer canvas', async () => {
        const blob = new Blob(['png'], { type: 'image/png' });
        const canvas = canvasWith([blob]);
        await expect(encodeCreatorCanvas(canvas, 'png')).resolves.toBe(blob);
        expect(canvas.width).toBe(1080);
        expect(canvas.height).toBe(1350);
        expect(canvas.toBlob).toHaveBeenCalledWith(
            expect.any(Function),
            'image/png',
            1,
        );
    });

    it('flattens JPEG transparency to white at the exact original dimensions', async () => {
        const source = canvasWith([]);
        const jpeg = new Blob(['jpeg'], { type: 'image/jpeg' });
        const target = canvasWith([jpeg]);
        const context = {
            fillStyle: '',
            fillRect: vi.fn(),
            drawImage: vi.fn(),
        };
        Object.assign(target, { getContext: vi.fn(() => context) });
        vi.stubGlobal('document', { createElement: vi.fn(() => target) });
        await expect(encodeCreatorCanvas(source, 'jpeg')).resolves.toBe(jpeg);
        expect([target.width, target.height]).toEqual([
            source.width,
            source.height,
        ]);
        expect(context.fillStyle).toBe('#ffffff');
        expect(context.fillRect).toHaveBeenCalledWith(0, 0, 1080, 1350);
        expect(context.drawImage).toHaveBeenCalledWith(source, 0, 0);
        expect(context.fillRect.mock.invocationCallOrder[0]).toBeLessThan(
            context.drawImage.mock.invocationCallOrder[0],
        );
    });

    it('lowers WebP quality without downscaling or losing content', async () => {
        const small = new Blob(['small'], { type: 'image/webp' });
        const canvas = canvasWith([
            new Blob(['too-large'], { type: 'image/webp' }),
            small,
        ]);
        await expect(encodeCreatorCanvas(canvas, 'webp', 5)).resolves.toBe(
            small,
        );
        expect(canvas.toBlob).toHaveBeenNthCalledWith(
            1,
            expect.any(Function),
            'image/webp',
            0.94,
        );
        expect(canvas.toBlob).toHaveBeenNthCalledWith(
            2,
            expect.any(Function),
            'image/webp',
            0.88,
        );
        expect([canvas.width, canvas.height]).toEqual([1080, 1350]);
    });

    it('rejects MIME fallback instead of naming PNG bytes as WebP', async () => {
        await expect(
            encodeCreatorCanvas(
                canvasWith([new Blob(['png'], { type: 'image/png' })]),
                'webp',
            ),
        ).rejects.toThrow('cannot export WEBP');
    });

    it('rejects a null encoder result', async () => {
        await expect(
            encodeCreatorCanvas(canvasWith([null]), 'png'),
        ).rejects.toThrow('No export was saved');
    });

    it('fails oversized PNG instead of silently discarding layers or resizing', async () => {
        const canvas = canvasWith([new Blob(['large'], { type: 'image/png' })]);
        await expect(encodeCreatorCanvas(canvas, 'png', 1)).rejects.toThrow(
            'exceeds 8 MB',
        );
        expect(canvas.toBlob).toHaveBeenCalledTimes(1);
        expect([canvas.width, canvas.height]).toEqual([1080, 1350]);
    });
});

describe('Creator ZIP structure', () => {
    it('writes valid local records, central directory offsets, CRC32 and original file bytes', async () => {
        const files = [
            {
                name: 'slide-01.png',
                blob: new Blob(['123456789'], { type: 'image/png' }),
            },
            {
                name: 'slide-02.webp',
                blob: new Blob([new Uint8Array([0, 1, 255, 8])], {
                    type: 'image/webp',
                }),
            },
        ];
        const zip = await creatorZip(files);
        expect(zip.type).toBe('application/zip');
        const bytes = new Uint8Array(await zip.arrayBuffer());
        const view = new DataView(bytes.buffer);
        const decoder = new TextDecoder();
        const localOffsets: number[] = [];
        let offset = 0;
        for (const file of files) {
            localOffsets.push(offset);
            expect(view.getUint32(offset, true)).toBe(0x04034b50);
            expect(view.getUint16(offset + 8, true)).toBe(0);
            const size = view.getUint32(offset + 18, true);
            const length = view.getUint16(offset + 26, true);
            expect(view.getUint32(offset + 22, true)).toBe(size);
            expect(
                decoder.decode(
                    bytes.subarray(offset + 30, offset + 30 + length),
                ),
            ).toBe(file.name);
            expect(
                bytes.subarray(
                    offset + 30 + length,
                    offset + 30 + length + size,
                ),
            ).toEqual(new Uint8Array(await file.blob.arrayBuffer()));
            offset += 30 + length + size;
        }
        expect(view.getUint32(14, true)).toBe(0xcbf43926);
        const centralOffset = offset;
        for (const [index, file] of files.entries()) {
            expect(view.getUint32(offset, true)).toBe(0x02014b50);
            expect(view.getUint16(offset + 10, true)).toBe(0);
            const length = view.getUint16(offset + 28, true);
            expect(
                decoder.decode(
                    bytes.subarray(offset + 46, offset + 46 + length),
                ),
            ).toBe(file.name);
            expect(view.getUint32(offset + 42, true)).toBe(localOffsets[index]);
            expect(view.getUint32(offset + 16, true)).toBe(
                view.getUint32(localOffsets[index] + 14, true),
            );
            offset += 46 + length;
        }
        expect(view.getUint32(offset, true)).toBe(0x06054b50);
        expect(view.getUint16(offset + 8, true)).toBe(2);
        expect(view.getUint16(offset + 10, true)).toBe(2);
        expect(view.getUint32(offset + 12, true)).toBe(offset - centralOffset);
        expect(view.getUint32(offset + 16, true)).toBe(centralOffset);
        expect(offset + 22).toBe(bytes.length);
    });

    it.each([
        '../slide.png',
        'folder/slide.png',
        'slide\\bad.png',
        '',
        'a'.repeat(121),
    ])('rejects unsafe filenames: %s', async (name) => {
        await expect(creatorZip([{ name, blob: new Blob() }])).rejects.toThrow(
            'unique and safe',
        );
    });

    it('rejects duplicate filenames', async () => {
        const file = { name: 'slide.png', blob: new Blob() };
        await expect(creatorZip([file, file])).rejects.toThrow(
            'unique and safe',
        );
    });

    it('enforces one to ten slides', async () => {
        await expect(creatorZip([])).rejects.toThrow('one and ten');
        await expect(
            creatorZip(
                Array.from({ length: 11 }, (_, index) => ({
                    name: `${index}.png`,
                    blob: new Blob(),
                })),
            ),
        ).rejects.toThrow('one and ten');
    });
});
