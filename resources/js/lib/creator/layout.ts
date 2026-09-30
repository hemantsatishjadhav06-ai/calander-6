import { clampNumber } from './grade';
import type { CreatorCrop, CreatorTextLayer } from './types';

export type TextLine = { text: string; width: number; x: number; y: number };
export type TextLayout = {
    lines: TextLine[];
    lineHeight: number;
    totalLines: number;
    overflow: boolean;
};
export function canvasFont(
    layer: Pick<CreatorTextLayer, 'font_weight' | 'font_size' | 'font_family'>,
): string {
    return `${layer.font_weight} ${layer.font_size}px "${layer.font_family}"`;
}
/** Preserves explicit newlines and breaks long words by grapheme, including emoji sequences. */
export function wrapText(
    value: string,
    maxWidth: number,
    measure: (text: string) => number,
): string[] {
    const width = clampNumber(maxWidth, 1, 16384, 400);
    const lines: string[] = [];
    const segmenter = new Intl.Segmenter(undefined, {
        granularity: 'grapheme',
    });
    for (const paragraph of value.replace(/\r\n?/g, '\n').split('\n')) {
        let line = '';
        const tokens = paragraph.match(/\S+|[\t ]+/gu) ?? [];
        for (const token of tokens) {
            const normalized = token.replace(/\t/g, '    ');
            if (measure(line + normalized) <= width) {
                line += normalized;
                continue;
            }
            if (line) {
                lines.push(line.trimEnd());
                line = '';
            }
            if (/^\s+$/u.test(normalized)) continue;
            for (const { segment } of segmenter.segment(normalized)) {
                if (line && measure(line + segment) > width) {
                    lines.push(line);
                    line = '';
                }
                line += segment;
            }
        }
        lines.push(line.trimEnd());
    }
    return lines;
}
export function layoutText(
    layer: CreatorTextLayer,
    measure: (text: string) => number,
): TextLayout {
    const wrapped = wrapText(layer.text, layer.width, measure);
    const lineHeight = layer.font_size * 1.2;
    const capacity = Math.max(
        0,
        1 + Math.floor((layer.height - layer.font_size + 0.00001) / lineHeight),
    );
    return {
        lines: wrapped.slice(0, capacity).map((text, index) => {
            const width = measure(text);
            return {
                text,
                width,
                x:
                    layer.text_align === 'center'
                        ? (layer.width - width) / 2
                        : layer.text_align === 'right'
                          ? layer.width - width
                          : 0,
                y: index * lineHeight,
            };
        }),
        lineHeight,
        totalLines: wrapped.length,
        overflow: wrapped.length > capacity,
    };
}
export type ImagePlacement = {
    sx: number;
    sy: number;
    sw: number;
    sh: number;
    dx: number;
    dy: number;
    dw: number;
    dh: number;
};
export function imagePlacement(
    sourceWidth: number,
    sourceHeight: number,
    width: number,
    height: number,
    fit: 'cover' | 'contain',
    crop?: CreatorCrop,
): ImagePlacement {
    if (
        ![sourceWidth, sourceHeight, width, height].every(
            (number) => Number.isFinite(number) && number > 0,
        )
    )
        throw new Error('Image dimensions must be positive.');
    let sx = (crop?.x ?? 0) * sourceWidth;
    let sy = (crop?.y ?? 0) * sourceHeight;
    let sw = (crop?.width ?? 1) * sourceWidth;
    let sh = (crop?.height ?? 1) * sourceHeight;
    if (fit === 'cover') {
        const targetRatio = width / height;
        const sourceRatio = sw / sh;
        if (sourceRatio > targetRatio) {
            const next = sh * targetRatio;
            sx += (sw - next) / 2;
            sw = next;
        } else {
            const next = sw / targetRatio;
            sy += (sh - next) / 2;
            sh = next;
        }
        return { sx, sy, sw, sh, dx: 0, dy: 0, dw: width, dh: height };
    }
    const scale = Math.min(width / sw, height / sh);
    const dw = sw * scale;
    const dh = sh * scale;
    return {
        sx,
        sy,
        sw,
        sh,
        dx: (width - dw) / 2,
        dy: (height - dh) / 2,
        dw,
        dh,
    };
}
/** Inverse layer transform for pointer hit tests and drawing input. */
export function toLayerPoint(
    point: { x: number; y: number },
    layer: {
        x: number;
        y: number;
        width: number;
        height: number;
        rotation: number;
    },
): { x: number; y: number } {
    const angle = (-layer.rotation * Math.PI) / 180;
    const dx = point.x - (layer.x + layer.width / 2);
    const dy = point.y - (layer.y + layer.height / 2);
    return {
        x: dx * Math.cos(angle) - dy * Math.sin(angle) + layer.width / 2,
        y: dx * Math.sin(angle) + dy * Math.cos(angle) + layer.height / 2,
    };
}
