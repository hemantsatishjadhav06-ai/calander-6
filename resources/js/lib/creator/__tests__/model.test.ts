import { describe, expect, it } from 'vitest';

import {
    addLayer,
    addSlide,
    assertDocument,
    CANVAS_PRESETS,
    commitHistory,
    createDocument,
    createHistory,
    createLayer,
    duplicateLayer,
    duplicateSlide,
    importLayerDescriptors,
    normalizeDocument,
    parseSerializedDocument,
    redoHistory,
    removeLayer,
    removeSlide,
    reorderLayer,
    reorderSlide,
    resizeDocument,
    serializeDocument,
    undoHistory,
    updateLayer,
    updateSlide,
} from '../index';

function scene() {
    const document = createDocument();
    return addLayer(
        document,
        document.slides[0].id,
        createLayer('text', { text: 'Hello', x: 10, y: 20 }),
    );
}
describe('creator scene documents', () => {
    it('scales drawing-local points when resizing its layer but preserves explicit replacement strokes', () => {
        let document = createDocument();
        const slideId = document.slides[0].id;
        const drawing = createLayer('drawing', {
            width: 100,
            height: 100,
            strokes: [
                {
                    tool: 'pen',
                    points: [{ x: 25, y: 50 }],
                    color: '#fff',
                    width: 4,
                },
            ],
        });
        document = addLayer(document, slideId, drawing);
        const resized = updateLayer(document, slideId, drawing.id, {
            width: 200,
            height: 300,
        });
        expect(resized.slides[0].layers[0]).toMatchObject({
            strokes: [{ width: 8, points: [{ x: 50, y: 150 }] }],
        });
        const replaced = updateLayer(document, slideId, drawing.id, {
            width: 200,
            strokes: drawing.strokes,
        });
        expect(replaced.slides[0].layers[0]).toMatchObject({
            strokes: drawing.strokes,
        });
    });
    it('enforces the serialized byte budget for multibyte text', () => {
        expect(() => parseSerializedDocument('😀'.repeat(500_001))).toThrow(
            /too large/,
        );
    });

    it('creates each required social preset and a portable schema', () => {
        expect(
            Object.values(CANVAS_PRESETS).map(({ width, height }) => [
                width,
                height,
            ]),
        ).toEqual([
            [1080, 1080],
            [1080, 1350],
            [1080, 1920],
            [1200, 630],
        ]);
        const document = scene();
        expect(parseSerializedDocument(serializeDocument(document))).toEqual(
            document,
        );
        expect(document.schema_version).toBe(1);
    });
    it('rejects unknown versions, unsafe asset IDs and malformed documents', () => {
        expect(() => assertDocument({ ...scene(), schema_version: 2 })).toThrow(
            /version/,
        );
        expect(() =>
            createLayer('image', { asset_id: 'https://example.com/photo.jpg' }),
        ).toThrow(/identifier/);
        expect(() =>
            createLayer('image', { asset_id: 'data:image/png;base64,xyz' }),
        ).toThrow(/identifier/);
        expect(() => assertDocument(null)).toThrow(/object/);
        expect(() => assertDocument({ ...scene(), slides: [] })).toThrow(
            /1–10/,
        );
        expect(() => parseSerializedDocument(' '.repeat(2_000_001))).toThrow(
            /too large/,
        );
    });
    it('clamps transient numbers, drops extraneous properties, and does not coerce unsafe values', () => {
        const document = scene();
        const layer = document.slides[0].layers[0];
        const result = normalizeDocument({
            ...document,
            unexpected: 'ignored',
            slides: [
                {
                    ...document.slides[0],
                    layers: [
                        {
                            ...layer,
                            x: NaN,
                            width: -1,
                            opacity: Infinity,
                            remote_url: 'https://bad.test',
                        },
                    ],
                },
            ],
        });
        expect(result.slides[0].layers[0]).toMatchObject({
            x: 0,
            width: 1,
            opacity: 1,
        });
        expect(JSON.stringify(result)).not.toContain('unexpected');
        expect(JSON.stringify(result)).not.toContain('remote_url');
        expect(() =>
            assertDocument({ ...document, canvas: { width: 0, height: 500 } }),
        ).toThrow();
        expect(() =>
            assertDocument({
                ...document,
                canvas: { width: 10.5, height: 500 },
            }),
        ).toThrow(/whole/);
        expect(() =>
            assertDocument({
                ...document,
                canvas: { width: 4096, height: 4096 },
            }),
        ).toThrow(/16 million/);
    });
    it('rejects invalid types, colors, fonts, finite ranges and duplicate IDs on strict validation', () => {
        const document = scene();
        const replace = (patch: object) => ({
            ...document,
            slides: [
                {
                    ...document.slides[0],
                    layers: [{ ...document.slides[0].layers[0], ...patch }],
                },
            ],
        });
        for (const patch of [
            { type: 'svg' },
            { color: 'url(https://bad.test)' },
            { font_family: 'evil' },
            { x: NaN },
            { opacity: 2 },
            { visible: 'false' },
        ])
            expect(() => assertDocument(replace(patch))).toThrow();
        expect(() =>
            assertDocument(replace({ id: document.slides[0].id })),
        ).toThrow(/unique/);
    });
    it('updates immutably, duplicates with fresh IDs and restores painter order', () => {
        const document = scene();
        const slideId = document.slides[0].id;
        const layerId = document.slides[0].layers[0].id;
        const edited = updateLayer(document, slideId, layerId, {
            x: 99,
            locked: true,
            visible: false,
        });
        expect(document.slides[0].layers[0].x).toBe(10);
        expect(edited.slides[0].layers[0]).toMatchObject({
            x: 99,
            locked: true,
            visible: false,
        });
        const duplicated = duplicateLayer(document, slideId, layerId);
        const copy = duplicated.slides[0].layers[1];
        expect(copy.id).not.toBe(layerId);
        expect(copy.x).toBe(34);
        const moved = reorderLayer(duplicated, slideId, copy.id, 0);
        expect(moved.slides[0].layers[0].id).toBe(copy.id);
        expect(
            removeLayer(moved, slideId, layerId).slides[0].layers,
        ).toHaveLength(1);
    });
    it('preserves layer types and IDs during patching', () => {
        const document = scene();
        const layer = document.slides[0].layers[0];
        const updated = updateLayer(document, document.slides[0].id, layer.id, {
            id: 'replacement',
            type: 'shape',
        });
        expect(updated.slides[0].layers[0]).toMatchObject({
            id: layer.id,
            type: 'text',
        });
    });
    it('adds, clones, reorders, updates and removes slides without duplicate layer IDs', () => {
        const document = scene();
        const cloned = duplicateSlide(document, document.slides[0].id);
        expect(cloned.slides).toHaveLength(2);
        expect(cloned.slides[1].layers[0].id).not.toBe(
            cloned.slides[0].layers[0].id,
        );
        const moved = reorderSlide(cloned, cloned.slides[1].id, 0);
        const updated = updateSlide(moved, moved.slides[0].id, {
            name: 'Cover',
            background_color: '#000',
        });
        expect(updated.slides[0].name).toBe('Cover');
        expect(removeSlide(updated, updated.slides[0].id).slides).toHaveLength(
            1,
        );
        expect(() => removeSlide(document, document.slides[0].id)).toThrow();
    });
    it('enforces bounded slides, layers and document-wide drawing budgets without truncation', () => {
        let document = createDocument();
        for (let index = 1; index < 10; index++) document = addSlide(document);
        expect(() => addSlide(document)).toThrow(/1–10/);
        const tooManyLayers = {
            ...document,
            slides: [
                {
                    ...document.slides[0],
                    layers: Array.from({ length: 65 }, () =>
                        createLayer('shape'),
                    ),
                },
            ],
        };
        expect(() => normalizeDocument(tooManyLayers)).toThrow(/64/);
    });
    it('checks total drawing points across multiple layers and requires at least one point', () => {
        let document = createDocument();
        const slideId = document.slides[0].id;
        const stroke = {
            tool: 'pen' as const,
            points: Array.from({ length: 2501 }, () => ({ x: 0, y: 0 })),
            color: '#fff',
            width: 2,
        };
        document = addLayer(
            document,
            slideId,
            createLayer('drawing', { strokes: [stroke] }),
        );
        expect(() =>
            addLayer(
                document,
                slideId,
                createLayer('drawing', { strokes: [stroke] }),
            ),
        ).toThrow(/drawing budget/);
        expect(() =>
            createLayer('drawing', { strokes: [{ ...stroke, points: [] }] }),
        ).toThrow(/points/);
    });
    it('resizes all slides with explicit content scaling and scales local drawing coordinates', () => {
        let document = scene();
        const slideId = document.slides[0].id;
        document = addLayer(
            document,
            slideId,
            createLayer('drawing', {
                strokes: [
                    {
                        tool: 'pen',
                        points: [{ x: 10, y: 20 }],
                        color: '#fff',
                        width: 8,
                    },
                ],
            }),
        );
        const scaled = resizeDocument(document, { width: 540, height: 540 });
        expect(scaled.slides[0].layers[0]).toMatchObject({
            x: 5,
            y: 10,
            font_size: 32,
        });
        expect(scaled.slides[0].layers[1]).toMatchObject({
            strokes: [{ width: 4, points: [{ x: 5, y: 10 }] }],
        });
        expect(
            resizeDocument(document, { width: 540, height: 540 }, false).slides,
        ).toEqual(document.slides);
    });
    it('retains deep immutable undo/redo snapshots and clears redo after a new edit', () => {
        const document = scene();
        const slideId = document.slides[0].id;
        const layerId = document.slides[0].layers[0].id;
        const initial = createHistory(document, 2);
        const next = updateLayer(document, slideId, layerId, { x: 100 });
        const committed = commitHistory(initial, next);
        next.slides[0].layers[0].x = 999;
        expect(committed.present.slides[0].layers[0].x).toBe(100);
        const undone = undoHistory(committed);
        expect(undone.present.slides[0].layers[0].x).toBe(10);
        expect(redoHistory(undone).present.slides[0].layers[0].x).toBe(100);
        expect(
            commitHistory(
                undone,
                updateLayer(document, slideId, layerId, { x: 200 }),
            ).future,
        ).toEqual([]);
        expect(commitHistory(initial, document)).toBe(initial);
        expect(undoHistory(initial)).toBe(initial);
    });
    it('bounds history memory and protects snapshots after repeated undo-redo', () => {
        let history = createHistory(scene(), 2);
        const slideId = history.present.slides[0].id;
        const layerId = history.present.slides[0].layers[0].id;
        for (let index = 0; index < 5; index++)
            history = commitHistory(
                history,
                updateLayer(history.present, slideId, layerId, { x: index }),
            );
        expect(history.past).toHaveLength(2);
        const roundtrip = redoHistory(undoHistory(history));
        expect(roundtrip.present).toEqual(history.present);
        roundtrip.present.slides[0].layers[0].x = 100;
        expect(history.present.slides[0].layers[0].x).toBe(4);
    });
    it('imports true layer positions and crops full-canvas provider output in stable z order', () => {
        const layers = importLayerDescriptors(
            [
                {
                    assetId: 'title',
                    name: 'Title',
                    zIndex: 2,
                    bounds: { normalized: [100, 200, 500, 600] },
                },
                { assetId: 'base', name: 'Base', zIndex: 0 },
                {
                    assetId: 'logo',
                    name: 'Logo',
                    zIndex: 2,
                    bounds: { absolute: [10, 20, 110, 70] },
                    sourceLayout: 'cropped',
                },
            ],
            { width: 1000, height: 500 },
        );
        expect(layers.map((layer) => layer.asset_id)).toEqual([
            'base',
            'title',
            'logo',
        ]);
        expect(layers[1]).toMatchObject({
            x: 100,
            y: 100,
            width: 400,
            crop: { x: 0.1, y: 0.2, width: 0.4 },
        });
        expect(layers[1].height).toBeCloseTo(200);
        expect(layers[1].crop?.height).toBeCloseTo(0.4);
        expect(layers[2]).toMatchObject({ x: 10, y: 20, width: 100 });
        expect(layers[2].height).toBeCloseTo(50);
        expect(layers[2].crop).toBeUndefined();
        expect(() =>
            importLayerDescriptors(
                [
                    {
                        assetId: 'x',
                        name: 'x',
                        zIndex: 1,
                        bounds: { normalized: [200, 100, 100, 500] },
                    },
                ],
                { width: 1000, height: 500 },
            ),
        ).toThrow(/positive area/);
    });
    it('constrains image crop ranges and grade values', () => {
        const image = createLayer('image', {
            asset_id: 'asset_1',
            crop: { x: 0.8, y: 0.9, width: 1, height: 1 },
            grade: {
                exposure: 10,
                contrast: Infinity,
                saturation: -200,
                hue: 900,
                warmth: NaN,
                vignette: 999,
            },
        });
        expect(image.crop?.width).toBeCloseTo(0.2);
        expect(image.crop?.height).toBeCloseTo(0.1);
        expect(image.grade).toEqual({
            exposure: 2,
            contrast: 0,
            saturation: -100,
            hue: 180,
            warmth: 0,
            vignette: 100,
        });
        const document = createDocument();
        expect(() => addLayer(document, 'missing', image)).toThrow(/not found/);
        const canonical = addLayer(document, document.slides[0].id, image);
        canonical.slides[0].layers[0] = {
            ...image,
            crop: { x: 0.9, y: 0, width: 0.5, height: 1 },
        };
        expect(() => assertDocument(canonical)).toThrow(/inside/);
    });
});
