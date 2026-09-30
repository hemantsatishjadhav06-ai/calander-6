import { describe, expect, it, vi } from 'vitest';

import {
    addLayer,
    addSlide,
    createDocument,
    createLayer,
    createSlide,
    NEUTRAL_GRADE,
    renderDocument,
    renderSlide,
} from '../index';
import type { CreatorDocument } from '../types';

type Call = {
    method: string;
    args: unknown[];
    alpha: number;
    composite: string;
    fill: string | CanvasGradient | CanvasPattern;
};
class FakeContext {
    calls: Call[] = [];
    globalAlpha = 1;
    globalCompositeOperation = 'source-over';
    fillStyle: string | CanvasGradient | CanvasPattern = '#000';
    font = '';
    textBaseline = '';
    textAlign = '';
    strokeStyle = '';
    lineWidth = 1;
    lineCap = '';
    lineJoin = '';
    imageSmoothingEnabled = true;
    imageSmoothingQuality = 'high';
    failRead = false;
    state: { globalAlpha: number; globalCompositeOperation: string }[] = [];
    log(method: string, ...args: unknown[]) {
        this.calls.push({
            method,
            args,
            alpha: this.globalAlpha,
            composite: this.globalCompositeOperation,
            fill: this.fillStyle,
        });
    }
    save() {
        this.state.push({
            globalAlpha: this.globalAlpha,
            globalCompositeOperation: this.globalCompositeOperation,
        });
        this.log('save');
    }
    restore() {
        Object.assign(this, this.state.pop());
        this.log('restore');
    }
    clearRect(...args: unknown[]) {
        this.log('clearRect', ...args);
    }
    fillRect(...args: unknown[]) {
        this.log('fillRect', ...args);
    }
    translate(...args: unknown[]) {
        this.log('translate', ...args);
    }
    rotate(...args: unknown[]) {
        this.log('rotate', ...args);
    }
    scale(...args: unknown[]) {
        this.log('scale', ...args);
    }
    beginPath() {
        this.log('beginPath');
    }
    closePath() {
        this.log('closePath');
    }
    rect(...args: unknown[]) {
        this.log('rect', ...args);
    }
    clip() {
        this.log('clip');
    }
    moveTo(...args: unknown[]) {
        this.log('moveTo', ...args);
    }
    lineTo(...args: unknown[]) {
        this.log('lineTo', ...args);
    }
    quadraticCurveTo(...args: unknown[]) {
        this.log('quadraticCurveTo', ...args);
    }
    ellipse(...args: unknown[]) {
        this.log('ellipse', ...args);
    }
    arc(...args: unknown[]) {
        this.log('arc', ...args);
    }
    fill() {
        this.log('fill');
    }
    stroke() {
        this.log('stroke');
    }
    drawImage(...args: unknown[]) {
        this.log('drawImage', ...args);
    }
    fillText(...args: unknown[]) {
        this.log('fillText', ...args);
    }
    measureText(text: string) {
        return { width: text.length * 10 };
    }
    getImageData(_x: number, _y: number, width: number, height: number) {
        if (this.failRead) throw new Error('Tainted');
        this.log('getImageData', width, height);
        return {
            width,
            height,
            data: new Uint8ClampedArray(width * height * 4).fill(100),
        };
    }
    putImageData(...args: unknown[]) {
        this.log('putImageData', ...args);
    }
}
class FakeCanvas {
    width = 0;
    height = 0;
    ctx = new FakeContext();
    getContext() {
        return this.ctx;
    }
}
const image = { width: 200, height: 100 } as unknown as CanvasImageSource;
function fixture() {
    let document = createDocument();
    document.canvas = { width: 100, height: 100 };
    const slideId = document.slides[0].id;
    for (const layer of [
        createLayer('image', {
            asset_id: 'photo',
            width: 100,
            height: 100,
            name: 'Photo',
        }),
        createLayer('text', {
            text: 'Title',
            width: 100,
            height: 100,
            font_size: 20,
            name: 'Title',
            rotation: 30,
            opacity: 0.5,
        }),
        createLayer('image', {
            asset_id: 'logo',
            role: 'logo',
            width: 20,
            height: 20,
            name: 'Logo',
        }),
        createLayer('shape', {
            shape: 'ellipse',
            width: 10,
            height: 10,
            name: 'Shape',
        }),
        createLayer('image', { asset_id: 'hidden_missing', visible: false }),
    ])
        document = addLayer(document, slideId, layer);
    const surfaces: FakeCanvas[] = [];
    const options = {
        assets: new Map([
            ['photo', image],
            ['logo', image],
        ]),
        canvasFactory: () => {
            const canvas = new FakeCanvas();
            surfaces.push(canvas);
            return canvas as unknown as HTMLCanvasElement;
        },
    };
    return { document, slideId, surfaces, options };
}
describe('creator complete compositing', () => {
    it('renders photos, text, logos and shapes in painter order, omitting hidden layers', async () => {
        const { document, slideId, surfaces, options } = fixture();
        const result = await renderSlide(document, slideId, options);
        expect(result.width).toBe(100);
        expect(result.height).toBe(100);
        const calls = surfaces[0].ctx.calls;
        expect(
            calls
                .filter((call) =>
                    ['drawImage', 'fillText', 'fill'].includes(call.method),
                )
                .map((call) => call.method),
        ).toEqual(['drawImage', 'fillText', 'drawImage', 'fill']);
        expect(calls.find((call) => call.method === 'fillText')).toMatchObject({
            args: ['Title', 0, 0],
            alpha: 0.5,
        });
        expect(
            calls.filter((call) => call.method === 'drawImage')[1].alpha,
        ).toBe(1);
        expect(
            calls.some(
                (call) =>
                    call.method === 'rotate' && call.args[0] === Math.PI / 6,
            ),
        ).toBe(true);
        expect(calls.filter((call) => call.method === 'save')).toHaveLength(4);
        expect(calls.filter((call) => call.method === 'restore')).toHaveLength(
            4,
        );
    });
    it('fails before rendering when any visible image is missing or fails to load', async () => {
        const { document, slideId, options, surfaces } = fixture();
        options.assets.delete('logo');
        await expect(renderSlide(document, slideId, options)).rejects.toThrow(
            /logo/,
        );
        expect(surfaces).toHaveLength(0);
        await expect(
            renderSlide(document, slideId, {
                ...options,
                assets: async () => {
                    throw new Error('Network failed');
                },
            }),
        ).rejects.toThrow(/incomplete/);
    });
    it('loads only asset IDs, deduplicates them per slide and waits for fonts', async () => {
        const { document, slideId, options } = fixture();
        const duplicated = addLayer(
            document,
            slideId,
            createLayer('image', { asset_id: 'photo' }),
        );
        const resolver = vi.fn(async () => image);
        const fontLoader = vi.fn(async () => []);
        await renderSlide(duplicated, slideId, {
            ...options,
            assets: resolver,
            fontLoader,
        });
        expect(resolver.mock.calls).toEqual([['photo'], ['logo']]);
        expect(fontLoader).toHaveBeenCalledWith('700 20px "Arial"');
    });
    it('rejects undecoded assets, invalid scene input and tainted export canvases', async () => {
        const { document, slideId, options } = fixture();
        await expect(
            renderSlide(document, slideId, {
                ...options,
                assets: () =>
                    ({ width: 0, height: 0 }) as unknown as CanvasImageSource,
            }),
        ).rejects.toThrow(/incomplete/);
        await expect(
            renderSlide(
                {
                    ...document,
                    schema_version: 2,
                } as unknown as CreatorDocument,
                slideId,
                options,
            ),
        ).rejects.toThrow(/version/);
        await expect(
            renderSlide(document, slideId, {
                ...options,
                canvasFactory: () => {
                    const canvas = new FakeCanvas();
                    canvas.ctx.failRead = true;
                    return canvas as unknown as HTMLCanvasElement;
                },
            }),
        ).rejects.toThrow(/not exportable/);
    });
    it('applies grade pixels to images and composites the result into the whole scene', async () => {
        const { document, slideId, options, surfaces } = fixture();
        const photo = document.slides[0].layers[0];
        if (photo.type !== 'image') throw new Error('fixture');
        photo.grade = {
            ...NEUTRAL_GRADE,
            exposure: 1,
            warmth: 50,
            vignette: 30,
        };
        await renderSlide(document, slideId, options);
        expect(surfaces).toHaveLength(2);
        expect(
            surfaces[1].ctx.calls.some(
                (call) => call.method === 'putImageData',
            ),
        ).toBe(true);
        expect(
            surfaces[0].ctx.calls.filter(
                (call) => call.method === 'drawImage',
            )[0].args[0],
        ).toBe(surfaces[1]);
        expect(
            surfaces[0].ctx.calls.some((call) => call.method === 'fillText'),
        ).toBe(true);
    });
    it('contains an eraser in its own drawing surface and renders single-point pen strokes', async () => {
        const { document, slideId, options, surfaces } = fixture();
        const withDrawing = addLayer(
            document,
            slideId,
            createLayer('drawing', {
                width: 100,
                height: 100,
                strokes: [
                    {
                        tool: 'pen',
                        points: [{ x: 10, y: 10 }],
                        width: 10,
                        color: '#f00',
                    },
                    {
                        tool: 'eraser',
                        points: [
                            { x: 10, y: 10 },
                            { x: 20, y: 20 },
                        ],
                        width: 5,
                        color: '#0000',
                    },
                ],
            }),
        );
        await renderSlide(withDrawing, slideId, options);
        expect(surfaces).toHaveLength(2);
        expect(
            surfaces[1].ctx.calls.some((call) => call.method === 'arc'),
        ).toBe(true);
        expect(
            surfaces[1].ctx.calls.find((call) => call.method === 'stroke'),
        ).toMatchObject({ composite: 'destination-out', fill: '#000000' });
        expect(
            surfaces[0].ctx.calls.every(
                (call) => call.composite === 'source-over',
            ),
        ).toBe(true);
    });
    it('exports every carousel slide at its exact dimensions or rejects the whole result', async () => {
        const { document, options } = fixture();
        const second = createSlide('Slide two');
        const carousel = addSlide(document, second);
        const result = await renderDocument(carousel, options);
        expect(result.map((output) => output.slideId)).toEqual(
            carousel.slides.map((slide) => slide.id),
        );
        expect(
            result.every(
                (output) =>
                    output.canvas.width === 100 && output.canvas.height === 100,
            ),
        ).toBe(true);
        const bad = addLayer(
            carousel,
            second.id,
            createLayer('image', { asset_id: 'missing' }),
        );
        await expect(renderDocument(bad, options)).rejects.toThrow(/missing/);
    });
    it('rejects aborted renders and does not leave a half-drawn output', async () => {
        const { document, slideId, options, surfaces } = fixture();
        const controller = new AbortController();
        controller.abort();
        await expect(
            renderSlide(document, slideId, {
                ...options,
                signal: controller.signal,
            }),
        ).rejects.toThrow();
        expect(surfaces).toHaveLength(0);
    });
});
