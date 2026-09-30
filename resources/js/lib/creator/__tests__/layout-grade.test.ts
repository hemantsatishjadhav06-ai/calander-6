import { describe, expect, it } from 'vitest';

import {
    applyGradeToPixels,
    canvasFont,
    createLayer,
    imagePlacement,
    isNeutralGrade,
    layoutText,
    NEUTRAL_GRADE,
    normalizeGrade,
    toLayerPoint,
    wrapText,
} from '../index';

describe('creator layout', () => {
    const measure = (text: string) => Array.from(text).length * 10;
    it('wraps words, explicit newlines, empty lines and long words deterministically', () => {
        expect(wrapText('One two three', 70, measure)).toEqual([
            'One two',
            'three',
        ]);
        expect(wrapText('one\n\ntwo', 100, measure)).toEqual([
            'one',
            '',
            'two',
        ]);
        expect(wrapText('abcdefgh', 30, measure)).toEqual(['abc', 'def', 'gh']);
        expect(wrapText('Hi\r\nthere', 100, measure)).toEqual(['Hi', 'there']);
        expect(wrapText('', 100, measure)).toEqual(['']);
    });
    it('keeps joined emoji graphemes intact even if wider than a text box', () => {
        expect(wrapText('👨‍👩‍👧‍👦👨‍👩‍👧‍👦', 10, () => 20)).toEqual(['👨‍👩‍👧‍👦', '👨‍👩‍👧‍👦']);
    });
    it('computes text alignment and reports text clipped by a short box', () => {
        const layer = createLayer('text', {
            text: 'One\nTwo\nThree',
            width: 100,
            height: 48,
            font_size: 20,
            text_align: 'center',
        });
        expect(canvasFont(layer)).toBe('700 20px "Arial"');
        const result = layoutText(layer, measure);
        expect(result.lines).toEqual([
            { text: 'One', width: 30, x: 35, y: 0 },
            { text: 'Two', width: 30, x: 35, y: 24 },
        ]);
        expect(result.overflow).toBe(true);
        expect(result.totalLines).toBe(3);
        expect(
            layoutText({ ...layer, text_align: 'right' }, measure).lines[0].x,
        ).toBe(70);
        expect(
            layoutText({ ...layer, height: 20, text: 'One' }, measure).lines,
        ).toHaveLength(1);
        expect(
            layoutText({ ...layer, height: 19, text: 'One' }, measure).lines,
        ).toHaveLength(0);
    });
    it('covers without distortion and contains with correct letterboxing', () => {
        expect(imagePlacement(200, 100, 100, 100, 'cover')).toEqual({
            sx: 50,
            sy: 0,
            sw: 100,
            sh: 100,
            dx: 0,
            dy: 0,
            dw: 100,
            dh: 100,
        });
        expect(imagePlacement(200, 100, 100, 100, 'contain')).toEqual({
            sx: 0,
            sy: 0,
            sw: 200,
            sh: 100,
            dx: 0,
            dy: 25,
            dw: 100,
            dh: 50,
        });
        expect(imagePlacement(100, 200, 100, 100, 'cover')).toMatchObject({
            sy: 50,
            sh: 100,
        });
        expect(
            imagePlacement(1000, 1000, 100, 100, 'contain', {
                x: 0.1,
                y: 0.2,
                width: 0.5,
                height: 0.5,
            }),
        ).toMatchObject({ sx: 100, sy: 200, sw: 500, sh: 500 });
        expect(() => imagePlacement(0, 100, 100, 100, 'cover')).toThrow();
    });
    it('inverts rotated pointer coordinates around the layer center', () => {
        expect(
            toLayerPoint(
                { x: 200, y: 250 },
                { x: 100, y: 200, width: 100, height: 100, rotation: 90 },
            ),
        ).toEqual({ x: 50, y: 0 });
    });
});
describe('creator pixel grading', () => {
    it('keeps neutral pixels byte-identical and never changes alpha', () => {
        const pixels = new Uint8ClampedArray([10, 20, 30, 128, 40, 50, 60, 0]);
        applyGradeToPixels(pixels, 2, 1, { ...NEUTRAL_GRADE });
        expect([...pixels]).toEqual([10, 20, 30, 128, 40, 50, 60, 0]);
        applyGradeToPixels(pixels, 2, 1, { ...NEUTRAL_GRADE, exposure: 1 });
        expect([...pixels]).toEqual([20, 40, 60, 128, 40, 50, 60, 0]);
    });
    it('handles saturation, contrast, hue, warmth and finite control normalization', () => {
        const gray = new Uint8ClampedArray([255, 0, 0, 255]);
        applyGradeToPixels(gray, 1, 1, { ...NEUTRAL_GRADE, saturation: -100 });
        expect(gray[0]).toBe(gray[1]);
        expect(gray[1]).toBe(gray[2]);
        const flat = new Uint8ClampedArray([0, 200, 255, 255]);
        applyGradeToPixels(flat, 1, 1, { ...NEUTRAL_GRADE, contrast: -100 });
        expect([...flat]).toEqual([128, 128, 128, 255]);
        const warm = new Uint8ClampedArray([100, 100, 100, 255]);
        applyGradeToPixels(warm, 1, 1, { ...NEUTRAL_GRADE, warmth: 100 });
        expect(warm[0]).toBeGreaterThan(warm[2]);
        const hue = new Uint8ClampedArray([255, 0, 0, 255]);
        applyGradeToPixels(hue, 1, 1, { ...NEUTRAL_GRADE, hue: 120 });
        expect(hue[1]).toBeGreaterThan(hue[0]);
        expect(
            normalizeGrade({ exposure: NaN, hue: Infinity, saturation: 300 }),
        ).toMatchObject({ exposure: 0, hue: 0, saturation: 100 });
        expect(isNeutralGrade({ ...NEUTRAL_GRADE })).toBe(true);
    });
    it('darkens image edges while preserving the center and validates pixel dimensions', () => {
        const pixels = new Uint8ClampedArray(9 * 9 * 4).fill(255);
        applyGradeToPixels(pixels, 9, 9, { ...NEUTRAL_GRADE, vignette: 100 });
        expect(pixels[0]).toBeLessThan(pixels[(4 * 9 + 4) * 4]);
        expect(pixels[(4 * 9 + 4) * 4]).toBe(255);
        expect(pixels[3]).toBe(255);
        expect(() =>
            applyGradeToPixels(pixels, 8, 9, { ...NEUTRAL_GRADE }),
        ).toThrow(/dimensions/);
    });
});
