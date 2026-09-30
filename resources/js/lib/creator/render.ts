import { applyGradeToPixels, isNeutralGrade } from './grade';
import { canvasFont, imagePlacement, layoutText } from './layout';
import { assertDocument } from './model';
import type {
    CreatorDocument,
    CreatorDrawingLayer,
    CreatorImageLayer,
    CreatorLayer,
    CreatorShapeLayer,
} from './types';

export type CreatorAssetResolver =
    | ReadonlyMap<string, CanvasImageSource>
    | ((assetId: string) => CanvasImageSource | Promise<CanvasImageSource>);
export type CreatorRenderOptions = {
    assets: CreatorAssetResolver;
    canvasFactory?: () => HTMLCanvasElement;
    fontLoader?: (font: string) => Promise<unknown>;
    signal?: AbortSignal;
};
export class CreatorRenderError extends Error {
    constructor(message: string, options?: ErrorOptions) {
        super(message, options);
        this.name = 'CreatorRenderError';
    }
}
function abort(signal?: AbortSignal): void {
    signal?.throwIfAborted();
}
function canvas(
    width: number,
    height: number,
    options: CreatorRenderOptions,
): HTMLCanvasElement {
    const result = options.canvasFactory
        ? options.canvasFactory()
        : globalThis.document?.createElement('canvas');
    if (!result)
        throw new CreatorRenderError(
            'Canvas rendering is unavailable in this environment.',
        );
    result.width = width;
    result.height = height;
    return result;
}
function context(
    canvas: HTMLCanvasElement,
    readPixels = false,
): CanvasRenderingContext2D {
    const result = canvas.getContext('2d', { willReadFrequently: readPixels });
    if (!result)
        throw new CreatorRenderError(
            'The browser could not create a 2D canvas.',
        );
    result.imageSmoothingEnabled = true;
    result.imageSmoothingQuality = 'high';
    return result;
}
function sourceSize(image: CanvasImageSource): {
    width: number;
    height: number;
} {
    const source = image as unknown as Record<string, unknown>;
    const width = Number(
        source.naturalWidth ??
            source.videoWidth ??
            source.displayWidth ??
            source.width,
    );
    const height = Number(
        source.naturalHeight ??
            source.videoHeight ??
            source.displayHeight ??
            source.height,
    );
    if (
        !Number.isFinite(width) ||
        !Number.isFinite(height) ||
        width <= 0 ||
        height <= 0
    )
        throw new CreatorRenderError(
            'An image asset is not decoded or has invalid dimensions.',
        );
    if (source.complete === false)
        throw new CreatorRenderError(
            'An image asset has not finished loading.',
        );
    return { width, height };
}
function roundedRect(
    ctx: CanvasRenderingContext2D,
    width: number,
    height: number,
    radius: number,
): void {
    const r = Math.min(radius, width / 2, height / 2);
    ctx.beginPath();
    ctx.moveTo(r, 0);
    ctx.lineTo(width - r, 0);
    ctx.quadraticCurveTo(width, 0, width, r);
    ctx.lineTo(width, height - r);
    ctx.quadraticCurveTo(width, height, width - r, height);
    ctx.lineTo(r, height);
    ctx.quadraticCurveTo(0, height, 0, height - r);
    ctx.lineTo(0, r);
    ctx.quadraticCurveTo(0, 0, r, 0);
    ctx.closePath();
}
function drawShape(
    ctx: CanvasRenderingContext2D,
    layer: CreatorShapeLayer,
): void {
    ctx.fillStyle = layer.fill;
    if (layer.shape === 'ellipse') {
        ctx.beginPath();
        ctx.ellipse(
            layer.width / 2,
            layer.height / 2,
            layer.width / 2,
            layer.height / 2,
            0,
            0,
            Math.PI * 2,
        );
    } else roundedRect(ctx, layer.width, layer.height, layer.radius);
    ctx.fill();
}
/** Bound temporary surfaces even for large, mostly off-canvas layers. */
function surface(
    layer: CreatorLayer,
    options: CreatorRenderOptions,
): { canvas: HTMLCanvasElement; ctx: CanvasRenderingContext2D; scale: number } {
    const scale = Math.min(1, 4096 / Math.max(layer.width, layer.height));
    const result = canvas(
        Math.max(1, Math.ceil(layer.width * scale)),
        Math.max(1, Math.ceil(layer.height * scale)),
        options,
    );
    const ctx = context(result, layer.type === 'image');
    ctx.scale(scale, scale);
    return { canvas: result, ctx, scale };
}
function drawImage(
    ctx: CanvasRenderingContext2D,
    layer: CreatorImageLayer,
    image: CanvasImageSource,
    options: CreatorRenderOptions,
): void {
    const size = sourceSize(image);
    const place = imagePlacement(
        size.width,
        size.height,
        layer.width,
        layer.height,
        layer.fit,
        layer.crop,
    );
    const draw = (target: CanvasRenderingContext2D): void =>
        target.drawImage(
            image,
            place.sx,
            place.sy,
            place.sw,
            place.sh,
            place.dx,
            place.dy,
            place.dw,
            place.dh,
        );
    if (isNeutralGrade(layer.grade)) {
        draw(ctx);
        return;
    }
    const temp = surface(layer, options);
    draw(temp.ctx);
    const data = temp.ctx.getImageData(
        0,
        0,
        temp.canvas.width,
        temp.canvas.height,
    );
    applyGradeToPixels(data.data, data.width, data.height, layer.grade);
    temp.ctx.putImageData(data, 0, 0);
    ctx.drawImage(temp.canvas, 0, 0, layer.width, layer.height);
}
function drawDrawing(
    ctx: CanvasRenderingContext2D,
    layer: CreatorDrawingLayer,
    options: CreatorRenderOptions,
): void {
    // Erasing is isolated to this drawing layer, never to the photo/text underneath it.
    const temp = surface(layer, options);
    temp.ctx.lineCap = 'round';
    temp.ctx.lineJoin = 'round';
    for (const stroke of layer.strokes) {
        temp.ctx.globalCompositeOperation =
            stroke.tool === 'eraser' ? 'destination-out' : 'source-over';
        temp.ctx.strokeStyle =
            stroke.tool === 'eraser' ? '#000000' : stroke.color;
        temp.ctx.fillStyle = temp.ctx.strokeStyle;
        temp.ctx.lineWidth = stroke.width;
        const [start] = stroke.points;
        if (stroke.points.length === 1) {
            temp.ctx.beginPath();
            temp.ctx.arc(start.x, start.y, stroke.width / 2, 0, Math.PI * 2);
            temp.ctx.fill();
        } else {
            temp.ctx.beginPath();
            temp.ctx.moveTo(start.x, start.y);
            for (const point of stroke.points.slice(1))
                temp.ctx.lineTo(point.x, point.y);
            temp.ctx.stroke();
        }
    }
    ctx.drawImage(temp.canvas, 0, 0, layer.width, layer.height);
}
async function resolveAssets(
    layers: CreatorLayer[],
    options: CreatorRenderOptions,
): Promise<Map<string, CanvasImageSource>> {
    const ids = [
        ...new Set(
            layers
                .filter(
                    (layer): layer is CreatorImageLayer =>
                        layer.visible && layer.type === 'image',
                )
                .map((layer) => layer.asset_id),
        ),
    ];
    const result = new Map<string, CanvasImageSource>();
    await Promise.all(
        ids.map(async (id) => {
            abort(options.signal);
            try {
                const image =
                    typeof options.assets === 'function'
                        ? await options.assets(id)
                        : options.assets.get(id);
                if (!image)
                    throw new CreatorRenderError(`Missing image asset: ${id}`);
                sourceSize(image);
                result.set(id, image);
            } catch (cause) {
                throw new CreatorRenderError(
                    `Unable to load image asset ${id}. Export was stopped to avoid an incomplete image.`,
                    { cause },
                );
            }
        }),
    );
    return result;
}
/** One renderer is shared by editor previews and full-resolution exports. Never draws editor handles. */
export async function renderSlide(
    input: CreatorDocument,
    slideId: string,
    options: CreatorRenderOptions,
): Promise<HTMLCanvasElement> {
    abort(options.signal);
    const document = assertDocument(input);
    const slide = document.slides.find((item) => item.id === slideId);
    if (!slide) throw new CreatorRenderError('Slide not found.');
    const images = await resolveAssets(slide.layers, options);
    const fonts = [
        ...new Set(
            slide.layers
                .filter((layer) => layer.visible && layer.type === 'text')
                .map((layer) =>
                    canvasFont(
                        layer as Extract<CreatorLayer, { type: 'text' }>,
                    ),
                ),
        ),
    ];
    const fontLoader =
        options.fontLoader ??
        (globalThis.document?.fonts
            ? (font: string) => globalThis.document.fonts.load(font)
            : undefined);
    if (fontLoader) await Promise.all(fonts.map((font) => fontLoader(font)));
    abort(options.signal);
    const result = canvas(
        document.canvas.width,
        document.canvas.height,
        options,
    );
    const ctx = context(result);
    ctx.clearRect(0, 0, result.width, result.height);
    ctx.fillStyle = slide.background_color;
    ctx.fillRect(0, 0, result.width, result.height);
    for (const layer of slide.layers) {
        if (!layer.visible) continue;
        abort(options.signal);
        ctx.save();
        try {
            ctx.globalAlpha = layer.opacity;
            ctx.translate(
                layer.x + layer.width / 2,
                layer.y + layer.height / 2,
            );
            ctx.rotate((layer.rotation * Math.PI) / 180);
            ctx.translate(-layer.width / 2, -layer.height / 2);
            ctx.beginPath();
            ctx.rect(0, 0, layer.width, layer.height);
            ctx.clip();
            if (layer.type === 'image')
                drawImage(ctx, layer, images.get(layer.asset_id)!, options);
            else if (layer.type === 'shape') drawShape(ctx, layer);
            else if (layer.type === 'drawing') drawDrawing(ctx, layer, options);
            else {
                ctx.font = canvasFont(layer);
                ctx.fillStyle = layer.color;
                ctx.textBaseline = 'top';
                ctx.textAlign = 'left';
                const layout = layoutText(
                    layer,
                    (text) => ctx.measureText(text).width,
                );
                for (const line of layout.lines)
                    ctx.fillText(line.text, line.x, line.y);
            }
        } catch (cause) {
            throw new CreatorRenderError(
                `Could not render layer "${layer.name}". Export was stopped.`,
                { cause },
            );
        } finally {
            ctx.restore();
        }
    }
    // Detect a tainted canvas before the caller encodes/uploads it, even without pixel grading.
    try {
        ctx.getImageData(0, 0, 1, 1);
    } catch (cause) {
        throw new CreatorRenderError(
            'An image is not exportable. Re-upload it as a workspace asset and try again.',
            { cause },
        );
    }
    return result;
}
/** All-or-nothing carousel output. Resolve one canvas at a time to limit temporary memory. */
export async function renderDocument(
    document: CreatorDocument,
    options: CreatorRenderOptions,
): Promise<{ slideId: string; canvas: HTMLCanvasElement }[]> {
    const normalized = assertDocument(document);
    const outputs: { slideId: string; canvas: HTMLCanvasElement }[] = [];
    for (const slide of normalized.slides)
        outputs.push({
            slideId: slide.id,
            canvas: await renderSlide(normalized, slide.id, options),
        });
    return outputs;
}
/** Load only URLs in the caller's explicit trusted asset registry, never values from a scene. */
export function createAssetLoader(
    urls: ReadonlyMap<string, string>,
): (assetId: string) => Promise<HTMLImageElement> {
    const cache = new Map<string, Promise<HTMLImageElement>>();
    return (assetId) => {
        const cached = cache.get(assetId);
        if (cached) return cached;
        const url = urls.get(assetId);
        if (!url)
            return Promise.reject(
                new CreatorRenderError(`Missing image asset: ${assetId}`),
            );
        const promise = new Promise<HTMLImageElement>((resolve, reject) => {
            const image = new Image();
            image.crossOrigin = 'anonymous';
            image.onload = () => {
                try {
                    sourceSize(image);
                    resolve(image);
                } catch (error) {
                    reject(error);
                }
            };
            image.onerror = () =>
                reject(
                    new CreatorRenderError(
                        `Unable to load image asset: ${assetId}`,
                    ),
                );
            image.src = url;
        });
        cache.set(assetId, promise);
        void promise.catch(() => cache.delete(assetId));
        return promise;
    };
}
