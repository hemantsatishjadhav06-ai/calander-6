import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    CreatorCanvas,
    pointInCreatorLayer,
} from '@/components/creator/creator-canvas';
import { createDocument, createLayer } from '@/lib/creator';
import type * as CreatorModule from '@/lib/creator';
import type { CreatorLayer } from '@/lib/creator';

const mocks = vi.hoisted(() => ({ renderSlide: vi.fn() }));
vi.mock('@/lib/creator', async (original) => ({
    ...(await original<typeof CreatorModule>()),
    renderSlide: mocks.renderSlide,
}));

function setup(
    layers: CreatorLayer[],
    selectedId: string | null = null,
    tool: 'select' | 'pen' | 'eraser' = 'select',
) {
    const document = createDocument();
    document.canvas = { width: 400, height: 400 };
    document.slides[0].layers = layers;
    const props = {
        document,
        slideId: document.slides[0].id,
        selectedId,
        tool,
        loadAsset: vi.fn(),
        onSelect: vi.fn(),
        onTransform: vi.fn(),
        onStroke: vi.fn(),
    };
    const rendered = render(<CreatorCanvas {...props} />);
    const frame = screen.getByRole('application', {
        name: 'Editable post canvas',
    });
    vi.spyOn(frame, 'getBoundingClientRect').mockReturnValue({
        x: 10,
        y: 20,
        left: 10,
        top: 20,
        width: 200,
        height: 200,
        right: 210,
        bottom: 220,
        toJSON: () => ({}),
    });
    return { ...rendered, frame, props };
}

function point(x: number, y: number) {
    return {
        clientX: 10 + x / 2,
        clientY: 20 + y / 2,
        pointerId: 1,
        button: 0,
        pointerType: 'mouse',
    };
}

beforeEach(() => {
    vi.spyOn(window, 'requestAnimationFrame').mockReturnValue(1);
    vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(() => {});
    Object.defineProperty(HTMLElement.prototype, 'setPointerCapture', {
        configurable: true,
        value: vi.fn(),
    });
    mocks.renderSlide.mockReset();
});

afterEach(() => vi.restoreAllMocks());

describe('Creator canvas hit testing', () => {
    it('tests a rotated rectangle in its own coordinates', () => {
        const layer = createLayer('shape', {
            x: 100,
            y: 100,
            width: 200,
            height: 40,
            rotation: 90,
        });
        expect(pointInCreatorLayer({ x: 200, y: 200 }, layer)).toBe(true);
        expect(pointInCreatorLayer({ x: 110, y: 120 }, layer)).toBe(false);
        expect(pointInCreatorLayer({ x: 200, y: 20 }, layer)).toBe(true);
    });

    it('selects the topmost visible unlocked hit and uses canvas-scaled drag deltas', () => {
        const back = createLayer('shape', {
            x: 40,
            y: 40,
            width: 200,
            height: 200,
        });
        const front = createLayer('text', {
            x: 60,
            y: 60,
            width: 140,
            height: 100,
        });
        const hidden = createLayer('shape', {
            x: 0,
            y: 0,
            width: 400,
            height: 400,
            visible: false,
        });
        const locked = createLayer('shape', {
            x: 0,
            y: 0,
            width: 400,
            height: 400,
            locked: true,
        });
        const { frame, props } = setup([back, front, hidden, locked]);
        fireEvent.pointerDown(frame, point(100, 100));
        fireEvent.pointerMove(frame, point(130, 150));
        expect(props.onTransform).not.toHaveBeenCalled();
        fireEvent.pointerUp(frame, point(130, 150));
        expect(props.onSelect).toHaveBeenCalledWith(front.id);
        expect(props.onTransform).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({ id: front.id, x: 90, y: 110 }),
        );
    });

    it('clears selection on empty space and ignores secondary-button presses', () => {
        const layer = createLayer('shape', {
            x: 0,
            y: 0,
            width: 40,
            height: 40,
        });
        const { frame, props } = setup([layer], layer.id);
        fireEvent.pointerDown(frame, { ...point(10, 10), button: 2 });
        expect(props.onSelect).not.toHaveBeenCalled();
        fireEvent.pointerDown(frame, point(200, 200));
        fireEvent.pointerUp(frame, point(200, 200));
        expect(props.onSelect).toHaveBeenCalledWith(null);
        expect(props.onTransform).not.toHaveBeenCalled();
    });

    it.each(['pointerCancel', 'lostPointerCapture'] as const)(
        'discards unfinished transforms on %s',
        (event) => {
            const layer = createLayer('shape', { width: 200, height: 200 });
            const { frame, props } = setup([layer], layer.id);
            fireEvent.pointerDown(frame, point(30, 30));
            fireEvent.pointerMove(frame, point(100, 100));
            fireEvent[event](frame, point(100, 100));
            fireEvent.pointerUp(frame, point(100, 100));
            expect(props.onTransform).not.toHaveBeenCalled();
        },
    );

    it('resizes from the corner and commits once despite bubbling pointer events', () => {
        const layer = createLayer('shape', {
            x: 10,
            y: 20,
            width: 100,
            height: 80,
        });
        const { props } = setup([layer], layer.id);
        const handle = screen.getByRole('button', {
            name: 'Resize selected layer',
        });
        fireEvent.pointerDown(handle, point(110, 100));
        fireEvent.pointerMove(handle, point(140, 120));
        fireEvent.pointerUp(handle, point(140, 120));
        expect(props.onTransform).toHaveBeenCalledExactlyOnceWith(
            expect.objectContaining({ x: 10, y: 20, width: 130, height: 100 }),
        );
    });

    it('nudges one pixel with arrows and ten with Shift without intercepting other keys', () => {
        const layer = createLayer('text', { x: 50, y: 60 });
        const { frame, props } = setup([layer], layer.id);
        fireEvent.keyDown(frame, { key: 'ArrowRight' });
        fireEvent.keyDown(frame, { key: 'ArrowUp', shiftKey: true });
        fireEvent.keyDown(frame, { key: 'a' });
        expect(props.onTransform).toHaveBeenNthCalledWith(
            1,
            expect.objectContaining({ x: 51, y: 60 }),
        );
        expect(props.onTransform).toHaveBeenNthCalledWith(
            2,
            expect.objectContaining({ x: 50, y: 50 }),
        );
        expect(props.onTransform).toHaveBeenCalledTimes(2);
    });

    it('does not expose resize handles or keyboard movement for locked layers', () => {
        const layer = createLayer('shape', { locked: true });
        const { frame, props } = setup([layer], layer.id);
        expect(
            screen.queryByRole('button', { name: 'Resize selected layer' }),
        ).not.toBeInTheDocument();
        fireEvent.keyDown(frame, { key: 'ArrowRight' });
        expect(props.onTransform).not.toHaveBeenCalled();
    });

    it('records drawing points in canvas coordinates and drops cancelled strokes', () => {
        const { frame, props } = setup([], null, 'pen');
        fireEvent.pointerDown(frame, point(25, 35));
        fireEvent.pointerMove(frame, point(50, 70));
        fireEvent.pointerUp(frame, point(50, 70));
        expect(props.onStroke).toHaveBeenCalledExactlyOnceWith(
            [
                { x: 25, y: 35 },
                { x: 50, y: 70 },
            ],
            'pen',
        );
        fireEvent.pointerDown(frame, point(100, 100));
        fireEvent.pointerCancel(frame, point(100, 100));
        expect(props.onStroke).toHaveBeenCalledTimes(1);
        expect(props.onTransform).not.toHaveBeenCalled();
    });

    it('keeps editing available after a renderer or asset failure', async () => {
        let frameCallback: FrameRequestCallback | undefined;
        vi.mocked(window.requestAnimationFrame).mockImplementation(
            (callback) => {
                frameCallback = callback;
                return 1;
            },
        );
        mocks.renderSlide.mockRejectedValue(new Error('Photo could not load'));
        const layer = createLayer('text', { x: 50 });
        const { frame, props } = setup([layer], layer.id);
        await act(async () => {
            frameCallback?.(0);
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Photo could not load',
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Saving the project is still available',
        );
        fireEvent.keyDown(frame, { key: 'ArrowRight' });
        expect(props.onTransform).toHaveBeenCalledWith(
            expect.objectContaining({ x: 51 }),
        );
    });
});
