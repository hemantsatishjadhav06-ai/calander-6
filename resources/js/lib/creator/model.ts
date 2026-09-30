import { clampNumber, NEUTRAL_GRADE, normalizeGrade } from './grade';
import {
    CANVAS_PRESETS,
    CREATOR_LIMITS,
    CREATOR_SCHEMA_VERSION,
} from './types';
import type {
    CanvasPreset,
    CreatorCanvas,
    CreatorDocument,
    CreatorGrade,
    CreatorLayer,
    CreatorLayerOf,
    CreatorLayerType,
    CreatorSlide,
    ImportedLayerDescriptor,
} from './types';

export class CreatorValidationError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'CreatorValidationError';
    }
}
const ID = /^[A-Za-z0-9_-]{1,100}$/;
const COLOR = /^#(?:[\da-f]{3}|[\da-f]{4}|[\da-f]{6}|[\da-f]{8})$/i;
export function createId(): string {
    return globalThis.crypto.randomUUID();
}
function fail(message: string): never {
    throw new CreatorValidationError(message);
}
function object(value: unknown, label: string): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        fail(`${label} must be an object.`);
    return value as Record<string, unknown>;
}
function id(value: unknown, label: string): string {
    if (typeof value !== 'string' || !ID.test(value))
        fail(`${label} must be a safe identifier (1–100 characters).`);
    return value;
}
function number(
    value: unknown,
    min: number,
    max: number,
    fallback: number,
    strict: boolean,
    label: string,
): number {
    if (value === undefined) return fallback;
    if (
        strict &&
        (typeof value !== 'number' ||
            !Number.isFinite(value) ||
            value < min ||
            value > max)
    )
        fail(`${label} must be between ${min} and ${max}.`);
    return clampNumber(value, min, max, fallback);
}
function text(
    value: unknown,
    fallback: string,
    maximum: number,
    strict: boolean,
    label: string,
): string {
    if (value === undefined) return fallback;
    if (typeof value !== 'string') fail(`${label} must be text.`);
    if (strict && value.length > maximum) fail(`${label} is too long.`);
    return value.slice(0, maximum);
}
function boolean(
    value: unknown,
    fallback: boolean,
    strict: boolean,
    label: string,
): boolean {
    if (value === undefined) return fallback;
    if (strict && typeof value !== 'boolean')
        fail(`${label} must be true or false.`);
    return typeof value === 'boolean' ? value : fallback;
}
function color(value: unknown, fallback: string, strict: boolean): string {
    if (value === undefined) return fallback;
    if (typeof value === 'string' && COLOR.test(value)) return value;
    if (strict) fail('Colors must be hexadecimal CSS colors.');
    return fallback;
}
function choice<T extends string | number>(
    value: unknown,
    choices: readonly T[],
    fallback: T,
    strict: boolean,
    label: string,
): T {
    if (value === undefined) return fallback;
    if (choices.includes(value as T)) return value as T;
    if (strict) fail(`${label} is unsupported.`);
    return fallback;
}
function parseLayer(value: unknown, strict: boolean): CreatorLayer {
    const raw = object(value, 'Layer');
    const base = {
        id: id(raw.id, 'Layer ID'),
        name: text(raw.name, 'Layer', 200, strict, 'Layer name'),
        visible: boolean(raw.visible, true, strict, 'Visibility'),
        locked: boolean(raw.locked, false, strict, 'Lock'),
        x: number(raw.x, -16384, 16384, 0, strict, 'X'),
        y: number(raw.y, -16384, 16384, 0, strict, 'Y'),
        width: number(raw.width, 1, 16384, 400, strict, 'Layer width'),
        height: number(raw.height, 1, 16384, 400, strict, 'Layer height'),
        rotation: number(raw.rotation, -360, 360, 0, strict, 'Rotation'),
        opacity: number(raw.opacity, 0, 1, 1, strict, 'Opacity'),
    };
    if (raw.type === 'image') {
        const gradeRaw =
            raw.grade === undefined ? {} : object(raw.grade, 'Grade');
        const grade: CreatorGrade = {
            exposure: number(gradeRaw.exposure, -2, 2, 0, strict, 'Exposure'),
            contrast: number(
                gradeRaw.contrast,
                -100,
                100,
                0,
                strict,
                'Contrast',
            ),
            saturation: number(
                gradeRaw.saturation,
                -100,
                100,
                0,
                strict,
                'Saturation',
            ),
            hue: number(gradeRaw.hue, -180, 180, 0, strict, 'Hue'),
            warmth: number(gradeRaw.warmth, -100, 100, 0, strict, 'Warmth'),
            vignette: number(gradeRaw.vignette, 0, 100, 0, strict, 'Vignette'),
        };
        const result: CreatorLayerOf<'image'> = {
            ...base,
            type: 'image',
            asset_id: id(raw.asset_id, 'Image asset ID'),
            fit: choice(
                raw.fit,
                ['cover', 'contain'],
                'cover',
                strict,
                'Image fit',
            ),
            grade,
        };
        if (raw.role !== undefined)
            result.role = choice<'logo'>(
                raw.role,
                ['logo'],
                'logo',
                strict,
                'Image role',
            );
        if (raw.crop !== undefined) {
            const crop = object(raw.crop, 'Crop');
            const x = number(crop.x, 0, 0.999999, 0, strict, 'Crop X');
            const y = number(crop.y, 0, 0.999999, 0, strict, 'Crop Y');
            const width = number(
                crop.width,
                0.000001,
                1,
                1 - x,
                strict,
                'Crop width',
            );
            const height = number(
                crop.height,
                0.000001,
                1,
                1 - y,
                strict,
                'Crop height',
            );
            if (strict && (x + width > 1.000000001 || y + height > 1.000000001))
                fail('Crop must stay inside its image.');
            result.crop = {
                x,
                y,
                width: Math.min(width, 1 - x),
                height: Math.min(height, 1 - y),
            };
        }
        return result;
    }
    if (raw.type === 'text')
        return {
            ...base,
            type: 'text',
            text: text(
                raw.text,
                'Your text',
                CREATOR_LIMITS.text,
                strict,
                'Text',
            ),
            font_family: choice(
                raw.font_family,
                ['Arial', 'Georgia', 'Courier New'],
                'Arial',
                strict,
                'Font',
            ),
            font_size: number(raw.font_size, 1, 1000, 64, strict, 'Font size'),
            font_weight: choice(
                raw.font_weight,
                [400, 700],
                700,
                strict,
                'Font weight',
            ),
            color: color(raw.color, '#ffffff', strict),
            text_align: choice(
                raw.text_align,
                ['left', 'center', 'right'],
                'left',
                strict,
                'Text alignment',
            ),
        };
    if (raw.type === 'shape')
        return {
            ...base,
            type: 'shape',
            shape: choice(
                raw.shape,
                ['rect', 'ellipse'],
                'rect',
                strict,
                'Shape',
            ),
            fill: color(raw.fill, '#6366f1', strict),
            radius: number(raw.radius, 0, 8192, 0, strict, 'Corner radius'),
        };
    if (raw.type === 'drawing') {
        const strokes = raw.strokes ?? [];
        if (!Array.isArray(strokes) || strokes.length > CREATOR_LIMITS.strokes)
            fail('Drawing has too many strokes.');
        return {
            ...base,
            type: 'drawing',
            strokes: strokes.map((value) => {
                const stroke = object(value, 'Stroke');
                if (
                    !Array.isArray(stroke.points) ||
                    !stroke.points.length ||
                    stroke.points.length > CREATOR_LIMITS.points
                )
                    fail('A stroke needs 1–5000 points.');
                return {
                    tool: choice(
                        stroke.tool,
                        ['pen', 'eraser'],
                        'pen',
                        strict,
                        'Drawing tool',
                    ),
                    color: color(stroke.color, '#ffffff', strict),
                    width: number(
                        stroke.width,
                        0.5,
                        1000,
                        8,
                        strict,
                        'Stroke width',
                    ),
                    points: stroke.points.map((value) => {
                        const point = object(value, 'Point');
                        return {
                            x: number(
                                point.x,
                                -16384,
                                16384,
                                0,
                                strict,
                                'Point X',
                            ),
                            y: number(
                                point.y,
                                -16384,
                                16384,
                                0,
                                strict,
                                'Point Y',
                            ),
                        };
                    }),
                };
            }),
        };
    }
    return fail('Unsupported layer type.');
}
function parseDocument(value: unknown, strict: boolean): CreatorDocument {
    const raw = object(value, 'Document');
    if (raw.schema_version !== CREATOR_SCHEMA_VERSION)
        fail('Unsupported creator document version.');
    const canvas = object(raw.canvas, 'Canvas');
    const width = number(
        canvas.width,
        1,
        CREATOR_LIMITS.canvas,
        1080,
        strict,
        'Canvas width',
    );
    const height = number(
        canvas.height,
        1,
        CREATOR_LIMITS.canvas,
        1080,
        strict,
        'Canvas height',
    );
    if (Math.round(width) * Math.round(height) > CREATOR_LIMITS.canvasPixels)
        fail('Canvas must not exceed 16 million pixels.');
    if (strict && (!Number.isInteger(width) || !Number.isInteger(height)))
        fail('Canvas dimensions must be whole pixels.');
    if (
        !Array.isArray(raw.slides) ||
        !raw.slides.length ||
        raw.slides.length > CREATOR_LIMITS.slides
    )
        fail('A document needs 1–10 slides.');
    const ids = new Set<string>();
    let strokes = 0;
    let points = 0;
    const unique = (value: string): string => {
        if (ids.has(value)) fail('Scene IDs must be unique.');
        ids.add(value);
        return value;
    };
    const slides = raw.slides.map((value, index): CreatorSlide => {
        const slide = object(value, 'Slide');
        if (
            !Array.isArray(slide.layers) ||
            slide.layers.length > CREATOR_LIMITS.layers
        )
            fail('A slide supports at most 64 layers.');
        return {
            id: unique(id(slide.id, 'Slide ID')),
            name: text(
                slide.name,
                `Slide ${index + 1}`,
                200,
                strict,
                'Slide name',
            ),
            background_color: color(slide.background_color, '#171717', strict),
            layers: slide.layers.map((value) => {
                const layer = parseLayer(value, strict);
                unique(layer.id);
                if (layer.type === 'drawing') {
                    strokes += layer.strokes.length;
                    points += layer.strokes.reduce(
                        (total, stroke) => total + stroke.points.length,
                        0,
                    );
                }
                return layer;
            }),
        };
    });
    if (strokes > CREATOR_LIMITS.strokes || points > CREATOR_LIMITS.points)
        fail(
            'The document exceeds its drawing budget (200 strokes / 5000 points).',
        );
    const result: CreatorDocument = {
        schema_version: CREATOR_SCHEMA_VERSION,
        canvas: { width: Math.round(width), height: Math.round(height) },
        slides,
    };
    if (
        new TextEncoder().encode(JSON.stringify(result)).byteLength >
        CREATOR_LIMITS.documentBytes
    )
        fail('Creator document is too large.');
    return result;
}
/** Strictly validates input, strips unknown fields, and returns a fresh canonical document. */
export function assertDocument(value: unknown): CreatorDocument {
    return parseDocument(value, true);
}
/** Normalizes transient editor values; structural errors and unsafe asset identifiers still fail. */
export function normalizeDocument(value: unknown): CreatorDocument {
    return parseDocument(value, false);
}
export function serializeDocument(value: CreatorDocument): string {
    return JSON.stringify(assertDocument(value));
}
export function parseSerializedDocument(value: string): CreatorDocument {
    if (
        value.length > CREATOR_LIMITS.documentBytes ||
        new TextEncoder().encode(value).byteLength >
            CREATOR_LIMITS.documentBytes
    )
        fail('Creator document is too large.');
    return assertDocument(JSON.parse(value) as unknown);
}
export function createLayer<T extends CreatorLayerType>(
    type: T,
    overrides: Partial<CreatorLayerOf<T>> = {},
): CreatorLayerOf<T> {
    return parseLayer(
        {
            id: createId(),
            name:
                type === 'image'
                    ? 'Image'
                    : type === 'text'
                      ? 'Text'
                      : type === 'shape'
                        ? 'Shape'
                        : 'Drawing',
            ...overrides,
            type,
        },
        false,
    ) as CreatorLayerOf<T>;
}
export function createSlide(name = 'Slide 1'): CreatorSlide {
    return { id: createId(), name, background_color: '#171717', layers: [] };
}
export function createDocument(
    preset: CanvasPreset = 'square',
): CreatorDocument {
    const { width, height } = CANVAS_PRESETS[preset];
    return {
        schema_version: 1,
        canvas: { width, height },
        slides: [createSlide()],
    };
}
function changeSlide(
    document: CreatorDocument,
    slideId: string,
    change: (slide: CreatorSlide) => CreatorSlide,
): CreatorDocument {
    if (!document.slides.some((slide) => slide.id === slideId))
        fail('Slide not found.');
    return normalizeDocument({
        ...document,
        slides: document.slides.map((slide) =>
            slide.id === slideId ? change(slide) : slide,
        ),
    });
}
export function updateSlide(
    document: CreatorDocument,
    slideId: string,
    patch: Partial<Pick<CreatorSlide, 'name' | 'background_color'>>,
): CreatorDocument {
    return changeSlide(document, slideId, (slide) => ({
        ...slide,
        ...patch,
        id: slide.id,
    }));
}
export function addLayer(
    document: CreatorDocument,
    slideId: string,
    layer: CreatorLayer,
    index?: number,
): CreatorDocument {
    return changeSlide(document, slideId, (slide) => {
        const layers = [...slide.layers];
        layers.splice(
            index === undefined
                ? layers.length
                : Math.trunc(
                      clampNumber(index, 0, layers.length, layers.length),
                  ),
            0,
            layer,
        );
        return { ...slide, layers };
    });
}
export function updateLayer(
    document: CreatorDocument,
    slideId: string,
    layerId: string,
    patch: Partial<CreatorLayer>,
): CreatorDocument {
    return changeSlide(document, slideId, (slide) => {
        if (!slide.layers.some((layer) => layer.id === layerId))
            fail('Layer not found.');
        return {
            ...slide,
            layers: slide.layers.map((layer) => {
                if (layer.id !== layerId) return layer;
                const next = parseLayer(
                    { ...layer, ...patch, id: layer.id, type: layer.type },
                    false,
                );
                if (
                    layer.type === 'drawing' &&
                    next.type === 'drawing' &&
                    !('strokes' in patch)
                ) {
                    const sx = next.width / layer.width;
                    const sy = next.height / layer.height;
                    if (sx !== 1 || sy !== 1)
                        next.strokes = layer.strokes.map((stroke) => ({
                            ...stroke,
                            width: stroke.width * Math.min(sx, sy),
                            points: stroke.points.map((point) => ({
                                x: point.x * sx,
                                y: point.y * sy,
                            })),
                        }));
                }
                return next;
            }),
        };
    });
}
export function removeLayer(
    document: CreatorDocument,
    slideId: string,
    layerId: string,
): CreatorDocument {
    return changeSlide(document, slideId, (slide) => {
        if (!slide.layers.some((layer) => layer.id === layerId))
            fail('Layer not found.');
        return {
            ...slide,
            layers: slide.layers.filter((layer) => layer.id !== layerId),
        };
    });
}
export function duplicateLayer(
    document: CreatorDocument,
    slideId: string,
    layerId: string,
): CreatorDocument {
    return changeSlide(document, slideId, (slide) => {
        const index = slide.layers.findIndex((layer) => layer.id === layerId);
        if (index < 0) fail('Layer not found.');
        const original = slide.layers[index];
        const copy = {
            ...structuredClone(original),
            id: createId(),
            name: `${original.name.slice(0, 195)} copy`,
            x: original.x + 24,
            y: original.y + 24,
        };
        return {
            ...slide,
            layers: [
                ...slide.layers.slice(0, index + 1),
                copy,
                ...slide.layers.slice(index + 1),
            ],
        };
    });
}
function reordered<T extends { id: string }>(
    items: T[],
    itemId: string,
    toIndex: number,
): T[] {
    const index = items.findIndex((item) => item.id === itemId);
    if (index < 0) fail('Item not found.');
    const result = [...items];
    const [item] = result.splice(index, 1);
    result.splice(
        Math.trunc(clampNumber(toIndex, 0, result.length, index)),
        0,
        item,
    );
    return result;
}
export function reorderLayer(
    document: CreatorDocument,
    slideId: string,
    layerId: string,
    toIndex: number,
): CreatorDocument {
    return changeSlide(document, slideId, (slide) => ({
        ...slide,
        layers: reordered(slide.layers, layerId, toIndex),
    }));
}
export function addSlide(
    document: CreatorDocument,
    slide = createSlide(`Slide ${document.slides.length + 1}`),
    index = document.slides.length,
): CreatorDocument {
    const slides = [...document.slides];
    slides.splice(
        Math.trunc(clampNumber(index, 0, slides.length, slides.length)),
        0,
        slide,
    );
    return normalizeDocument({ ...document, slides });
}
export function removeSlide(
    document: CreatorDocument,
    slideId: string,
): CreatorDocument {
    if (!document.slides.some((slide) => slide.id === slideId))
        fail('Slide not found.');
    return normalizeDocument({
        ...document,
        slides: document.slides.filter((slide) => slide.id !== slideId),
    });
}
export function duplicateSlide(
    document: CreatorDocument,
    slideId: string,
): CreatorDocument {
    const index = document.slides.findIndex((slide) => slide.id === slideId);
    if (index < 0) fail('Slide not found.');
    const slide = structuredClone(document.slides[index]);
    slide.id = createId();
    slide.name = `${slide.name.slice(0, 195)} copy`;
    slide.layers = slide.layers.map((layer) => ({ ...layer, id: createId() }));
    return addSlide(document, slide, index + 1);
}
export function reorderSlide(
    document: CreatorDocument,
    slideId: string,
    toIndex: number,
): CreatorDocument {
    return normalizeDocument({
        ...document,
        slides: reordered(document.slides, slideId, toIndex),
    });
}
/** Resize uses proportional coordinates; typography/stroke widths use the smaller axis scale. */
export function resizeDocument(
    document: CreatorDocument,
    canvas: CreatorCanvas,
    scaleContents = true,
): CreatorDocument {
    const width = Math.round(
        clampNumber(
            canvas.width,
            1,
            CREATOR_LIMITS.canvas,
            document.canvas.width,
        ),
    );
    const height = Math.round(
        clampNumber(
            canvas.height,
            1,
            CREATOR_LIMITS.canvas,
            document.canvas.height,
        ),
    );
    const sx = width / document.canvas.width;
    const sy = height / document.canvas.height;
    const scale = Math.min(sx, sy);
    return normalizeDocument({
        ...document,
        canvas: { width, height },
        slides: document.slides.map((slide) => ({
            ...slide,
            layers: slide.layers.map((layer) => {
                if (!scaleContents) return layer;
                const scaled = {
                    ...layer,
                    x: layer.x * sx,
                    y: layer.y * sy,
                    width: layer.width * sx,
                    height: layer.height * sy,
                };
                if (scaled.type === 'text') scaled.font_size *= scale;
                if (scaled.type === 'shape') scaled.radius *= scale;
                if (scaled.type === 'drawing')
                    scaled.strokes = scaled.strokes.map((stroke) => ({
                        ...stroke,
                        width: stroke.width * scale,
                        points: stroke.points.map((point) => ({
                            x: point.x * sx,
                            y: point.y * sy,
                        })),
                    }));
                return scaled;
            }),
        })),
    });
}
/** Convert only trusted asset IDs. Provider URLs must be ingested as owned assets before this step. */
export function importLayerDescriptors(
    descriptors: ImportedLayerDescriptor[],
    canvas: CreatorCanvas,
): CreatorLayerOf<'image'>[] {
    if (descriptors.length > CREATOR_LIMITS.layers)
        fail('Too many imported layers.');
    if (
        ![canvas.width, canvas.height].every(
            (value) =>
                Number.isInteger(value) &&
                value > 0 &&
                value <= CREATOR_LIMITS.canvas,
        ) ||
        canvas.width * canvas.height > CREATOR_LIMITS.canvasPixels
    )
        fail('Imported canvas dimensions are invalid.');
    return descriptors
        .map((descriptor, index) => ({ descriptor, index }))
        .sort(
            (a, b) =>
                a.descriptor.zIndex - b.descriptor.zIndex || a.index - b.index,
        )
        .map(({ descriptor }) => {
            if (!Number.isFinite(descriptor.zIndex))
                fail('Imported layer order must be finite.');
            const bounds =
                descriptor.bounds?.normalized ?? descriptor.bounds?.absolute;
            let left = 0;
            let top = 0;
            let right = 1;
            let bottom = 1;
            if (bounds) {
                if (bounds.length !== 4 || !bounds.every(Number.isFinite))
                    fail('Imported layer bounds are invalid.');
                const normalized = Boolean(descriptor.bounds?.normalized);
                left = clampNumber(
                    bounds[0] / (normalized ? 1000 : canvas.width),
                    0,
                    1,
                );
                top = clampNumber(
                    bounds[1] / (normalized ? 1000 : canvas.height),
                    0,
                    1,
                );
                right = clampNumber(
                    bounds[2] / (normalized ? 1000 : canvas.width),
                    0,
                    1,
                );
                bottom = clampNumber(
                    bounds[3] / (normalized ? 1000 : canvas.height),
                    0,
                    1,
                );
                if (right <= left || bottom <= top)
                    fail('Imported layer bounds must have positive area.');
            }
            return createLayer('image', {
                asset_id: descriptor.assetId,
                name: descriptor.name,
                x: left * canvas.width,
                y: top * canvas.height,
                width: (right - left) * canvas.width,
                height: (bottom - top) * canvas.height,
                fit: 'contain',
                grade: { ...NEUTRAL_GRADE },
                ...(bounds && descriptor.sourceLayout !== 'cropped'
                    ? {
                          crop: {
                              x: left,
                              y: top,
                              width: right - left,
                              height: bottom - top,
                          },
                      }
                    : {}),
            });
        });
}
export function patchGrade(
    layer: CreatorLayerOf<'image'>,
    patch: Partial<CreatorGrade>,
): CreatorGrade {
    return normalizeGrade({ ...layer.grade, ...patch });
}
