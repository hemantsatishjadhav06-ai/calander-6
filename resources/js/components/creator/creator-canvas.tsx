import type { PointerEvent } from 'react';
import { useEffect, useRef, useState } from 'react';

import { renderSlide } from '@/lib/creator';
import type { CreatorDocument, CreatorLayer } from '@/lib/creator';

type Point = { x: number; y: number };
type Props = {
    document: CreatorDocument;
    slideId: string;
    selectedId: string | null;
    loadAsset: (id: string) => Promise<HTMLImageElement>;
    onSelect: (id: string | null) => void;
    onTransform: (layer: CreatorLayer) => void;
    tool: 'select' | 'pen' | 'eraser';
    onStroke: (points: Point[], tool: 'pen' | 'eraser') => void;
};

export function pointInCreatorLayer(
    point: Point,
    layer: CreatorLayer,
): boolean {
    const dx = point.x - layer.x - layer.width / 2;
    const dy = point.y - layer.y - layer.height / 2;
    const radians = (-layer.rotation * Math.PI) / 180;
    const x = dx * Math.cos(radians) - dy * Math.sin(radians);
    const y = dx * Math.sin(radians) + dy * Math.cos(radians);
    return Math.abs(x) <= layer.width / 2 && Math.abs(y) <= layer.height / 2;
}

export function resizeCreatorLayer(
    layer: CreatorLayer,
    dx: number,
    dy: number,
): CreatorLayer {
    const angle = (layer.rotation * Math.PI) / 180;
    const width = Math.max(
        8,
        Math.min(
            8192,
            layer.width + dx * Math.cos(angle) + dy * Math.sin(angle),
        ),
    );
    const height = Math.max(
        8,
        Math.min(
            8192,
            layer.height - dx * Math.sin(angle) + dy * Math.cos(angle),
        ),
    );
    const dw = (width - layer.width) / 2;
    const dh = (height - layer.height) / 2;
    return {
        ...layer,
        width,
        height,
        x: layer.x + dw * Math.cos(angle) - dh * Math.sin(angle) - dw,
        y: layer.y + dw * Math.sin(angle) + dh * Math.cos(angle) - dh,
    };
}

export function CreatorCanvas({
    document: scene,
    slideId,
    selectedId,
    loadAsset,
    onSelect,
    onTransform,
    tool,
    onStroke,
}: Props) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const frameRef = useRef<HTMLDivElement>(null);
    const gesture = useRef<{
        start: Point;
        layer: CreatorLayer;
        resize: boolean;
    } | null>(null);
    const stroke = useRef<Point[] | null>(null);
    const [draftLayer, setDraftLayer] = useState<CreatorLayer | null>(null);
    const draftRef = useRef<CreatorLayer | null>(null);
    const [drawPoints, setDrawPoints] = useState<Point[]>([]);
    const [error, setError] = useState<string | null>(null);
    const slide = scene.slides.find((item) => item.id === slideId);
    const selected =
        draftLayer ?? slide?.layers.find((item) => item.id === selectedId);

    useEffect(() => {
        let cancelled = false;
        const request = window.requestAnimationFrame(() => {
            const preview = draftLayer
                ? {
                      ...scene,
                      slides: scene.slides.map((item) =>
                          item.id !== slideId
                              ? item
                              : {
                                    ...item,
                                    layers: item.layers.map((layer) =>
                                        layer.id === draftLayer.id
                                            ? draftLayer
                                            : layer,
                                    ),
                                },
                      ),
                  }
                : scene;
            void renderSlide(preview, slideId, { assets: loadAsset })
                .then((rendered) => {
                    if (cancelled || !canvasRef.current) return;
                    const canvas = canvasRef.current;
                    canvas.width = rendered.width;
                    canvas.height = rendered.height;
                    const context = canvas.getContext('2d');
                    if (!context)
                        throw new Error(
                            'Canvas rendering is unavailable in this browser.',
                        );
                    context.clearRect(0, 0, canvas.width, canvas.height);
                    context.drawImage(rendered, 0, 0);
                    setError(null);
                })
                .catch((reason: unknown) => {
                    if (!cancelled)
                        setError(
                            reason instanceof Error
                                ? reason.message
                                : 'Could not render the design.',
                        );
                });
        });
        return () => {
            cancelled = true;
            window.cancelAnimationFrame(request);
        };
    }, [scene, slideId, draftLayer, loadAsset]);

    function position(event: PointerEvent): Point {
        const bounds = frameRef.current!.getBoundingClientRect();
        return {
            x:
                ((event.clientX - bounds.left) * scene.canvas.width) /
                bounds.width,
            y:
                ((event.clientY - bounds.top) * scene.canvas.height) /
                bounds.height,
        };
    }

    function begin(event: PointerEvent<HTMLDivElement>, resize = false) {
        if (event.button !== 0 || !slide) return;
        const point = position(event);
        if (tool !== 'select' && !resize) {
            stroke.current = [point];
            setDrawPoints([point]);
            event.currentTarget.setPointerCapture(event.pointerId);
            return;
        }
        const layer = resize
            ? selected
            : [...slide.layers]
                  .reverse()
                  .find(
                      (item) =>
                          item.visible &&
                          !item.locked &&
                          pointInCreatorLayer(point, item),
                  );
        onSelect(layer?.id ?? null);
        if (!layer || layer.locked) return;
        event.preventDefault();
        event.currentTarget.setPointerCapture(event.pointerId);
        gesture.current = { start: point, layer, resize };
        draftRef.current = layer;
    }

    function move(event: PointerEvent<HTMLDivElement>) {
        const point = position(event);
        if (stroke.current) {
            if (stroke.current.length < 2000) {
                stroke.current.push(point);
                setDrawPoints([...stroke.current]);
            }
            return;
        }
        const active = gesture.current;
        if (!active) return;
        const dx = point.x - active.start.x;
        const dy = point.y - active.start.y;
        const layer = active.resize
            ? resizeCreatorLayer(active.layer, dx, dy)
            : {
                  ...active.layer,
                  x: Math.max(-8192, Math.min(8192, active.layer.x + dx)),
                  y: Math.max(-8192, Math.min(8192, active.layer.y + dy)),
              };
        draftRef.current = layer;
        setDraftLayer(layer);
    }

    function finish(cancelled = false) {
        if (stroke.current && !cancelled && tool !== 'select')
            onStroke(stroke.current, tool);
        if (gesture.current && draftRef.current && !cancelled)
            onTransform(draftRef.current);
        stroke.current = null;
        gesture.current = null;
        draftRef.current = null;
        setDraftLayer(null);
        setDrawPoints([]);
    }

    return (
        <div className="flex min-h-64 flex-1 flex-col items-center justify-center gap-3 overflow-auto bg-muted/50 p-6 sm:p-8">
            <div
                ref={frameRef}
                role="application"
                aria-label="Editable post canvas"
                tabIndex={0}
                className="relative w-full shrink-0 touch-none overflow-hidden bg-white shadow-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                style={{
                    aspectRatio: `${scene.canvas.width}/${scene.canvas.height}`,
                    maxWidth: (540 * scene.canvas.width) / scene.canvas.height,
                    cursor: tool === 'select' ? 'default' : 'crosshair',
                }}
                onPointerDown={(event) => begin(event)}
                onPointerMove={move}
                onPointerUp={() => finish()}
                onPointerCancel={() => finish(true)}
                onLostPointerCapture={() => finish(true)}
                onKeyDown={(event) => {
                    if (
                        !selected ||
                        selected.locked ||
                        ![
                            'ArrowLeft',
                            'ArrowRight',
                            'ArrowUp',
                            'ArrowDown',
                        ].includes(event.key)
                    )
                        return;
                    event.preventDefault();
                    const amount = event.shiftKey ? 10 : 1;
                    onTransform({
                        ...selected,
                        x:
                            selected.x +
                            (event.key === 'ArrowLeft'
                                ? -amount
                                : event.key === 'ArrowRight'
                                  ? amount
                                  : 0),
                        y:
                            selected.y +
                            (event.key === 'ArrowUp'
                                ? -amount
                                : event.key === 'ArrowDown'
                                  ? amount
                                  : 0),
                    });
                }}
            >
                <canvas
                    ref={canvasRef}
                    className="block h-full w-full"
                    aria-label="Current design preview"
                />
                {selected?.visible && tool === 'select' && (
                    <div
                        className="pointer-events-none absolute border-2 border-blue-500"
                        style={{
                            left: `${(selected.x / scene.canvas.width) * 100}%`,
                            top: `${(selected.y / scene.canvas.height) * 100}%`,
                            width: `${(selected.width / scene.canvas.width) * 100}%`,
                            height: `${(selected.height / scene.canvas.height) * 100}%`,
                            transform: `rotate(${selected.rotation}deg)`,
                        }}
                    >
                        {!selected.locked && selected.type !== 'drawing' && (
                            <div
                                role="button"
                                aria-label="Resize selected layer"
                                tabIndex={-1}
                                className="pointer-events-auto absolute -right-2 -bottom-2 size-4 cursor-nwse-resize rounded-full border-2 border-white bg-blue-500"
                                onPointerDown={(event) => {
                                    event.stopPropagation();
                                    begin(event, true);
                                }}
                                onPointerMove={move}
                                onPointerUp={() => finish()}
                                onPointerCancel={() => finish(true)}
                            />
                        )}
                    </div>
                )}
                {drawPoints.length > 0 && (
                    <svg
                        className="pointer-events-none absolute inset-0 size-full"
                        viewBox={`0 0 ${scene.canvas.width} ${scene.canvas.height}`}
                        aria-hidden="true"
                    >
                        <polyline
                            points={drawPoints
                                .map((point) => `${point.x},${point.y}`)
                                .join(' ')}
                            fill="none"
                            stroke={tool === 'eraser' ? '#fb7185' : '#3b82f6'}
                            strokeWidth={Math.max(3, scene.canvas.width / 250)}
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        />
                    </svg>
                )}
            </div>
            <p className="text-center text-xs text-muted-foreground">
                {scene.canvas.width} × {scene.canvas.height} ·{' '}
                {tool === 'select'
                    ? 'Drag to move · corner to resize · arrow keys to nudge'
                    : tool === 'eraser'
                      ? 'Erase strokes in the selected drawing layer'
                      : 'Draw a stroke. Each stroke can be undone'}
            </p>
            {error && (
                <p
                    role="alert"
                    className="max-w-md rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
                >
                    {error} Saving the project is still available; export
                    requires every visible asset to load.
                </p>
            )}
        </div>
    );
}
