import type { CreatorGrade } from './types';

export const NEUTRAL_GRADE: Readonly<CreatorGrade> = Object.freeze({
    exposure: 0,
    contrast: 0,
    saturation: 0,
    hue: 0,
    warmth: 0,
    vignette: 0,
});
export function clampNumber(
    value: unknown,
    min: number,
    max: number,
    fallback = min,
): number {
    return typeof value === 'number' && Number.isFinite(value)
        ? Math.min(max, Math.max(min, value))
        : fallback;
}
export function normalizeGrade(
    value: Partial<CreatorGrade> = {},
): CreatorGrade {
    return {
        exposure: clampNumber(value.exposure, -2, 2, 0),
        contrast: clampNumber(value.contrast, -100, 100, 0),
        saturation: clampNumber(value.saturation, -100, 100, 0),
        hue: clampNumber(value.hue, -180, 180, 0),
        warmth: clampNumber(value.warmth, -100, 100, 0),
        vignette: clampNumber(value.vignette, 0, 100, 0),
    };
}
export function isNeutralGrade(value: CreatorGrade): boolean {
    return Object.values(normalizeGrade(value)).every((number) => number === 0);
}
/** Pixel-space grading makes previews and exports match, without browser CSS-filter differences. */
export function applyGradeToPixels(
    pixels: Uint8ClampedArray,
    width: number,
    height: number,
    value: CreatorGrade,
): void {
    if (
        !Number.isInteger(width) ||
        !Number.isInteger(height) ||
        width <= 0 ||
        height <= 0 ||
        pixels.length !== width * height * 4
    ) {
        throw new Error('Pixel buffer dimensions do not match.');
    }
    const grade = normalizeGrade(value);
    if (isNeutralGrade(grade)) return;
    const exposure = 2 ** grade.exposure;
    const contrast = 1 + grade.contrast / 100;
    const saturation = 1 + grade.saturation / 100;
    const angle = (grade.hue * Math.PI) / 180;
    const cosine = Math.cos(angle);
    const sine = Math.sin(angle);
    // CSS hue-rotate's luminance-preserving matrix, followed by a gentle RGB warmth balance.
    const matrix = [
        0.213 + cosine * 0.787 - sine * 0.213,
        0.715 - cosine * 0.715 - sine * 0.715,
        0.072 - cosine * 0.072 + sine * 0.928,
        0.213 - cosine * 0.213 + sine * 0.143,
        0.715 + cosine * 0.285 + sine * 0.14,
        0.072 - cosine * 0.072 - sine * 0.283,
        0.213 - cosine * 0.213 - sine * 0.787,
        0.715 - cosine * 0.715 + sine * 0.715,
        0.072 + cosine * 0.928 + sine * 0.072,
    ];
    const warmth = grade.warmth / 100;
    for (let index = 0; index < pixels.length; index += 4) {
        if (pixels[index + 3] === 0) continue;
        let red = ((pixels[index] * exposure) / 255 - 0.5) * contrast + 0.5;
        let green =
            ((pixels[index + 1] * exposure) / 255 - 0.5) * contrast + 0.5;
        let blue =
            ((pixels[index + 2] * exposure) / 255 - 0.5) * contrast + 0.5;
        const luminance = red * 0.2126 + green * 0.7152 + blue * 0.0722;
        red = luminance + (red - luminance) * saturation;
        green = luminance + (green - luminance) * saturation;
        blue = luminance + (blue - luminance) * saturation;
        let vignette = 1;
        if (grade.vignette > 0) {
            const pixel = index / 4;
            const nx = (((pixel % width) + 0.5) / width) * 2 - 1;
            const ny = ((Math.floor(pixel / width) + 0.5) / height) * 2 - 1;
            const distance = Math.min(
                1,
                Math.sqrt(nx * nx + ny * ny) / Math.SQRT2,
            );
            const edge = Math.max(0, (distance - 0.25) / 0.75);
            vignette = 1 - edge * edge * (grade.vignette / 100) * 0.85;
        }
        pixels[index] =
            (matrix[0] * red + matrix[1] * green + matrix[2] * blue) *
            (1 + warmth * 0.22) *
            vignette *
            255;
        pixels[index + 1] =
            (matrix[3] * red + matrix[4] * green + matrix[5] * blue) *
            (1 + warmth * 0.03) *
            vignette *
            255;
        pixels[index + 2] =
            (matrix[6] * red + matrix[7] * green + matrix[8] * blue) *
            (1 - warmth * 0.22) *
            vignette *
            255;
    }
}
