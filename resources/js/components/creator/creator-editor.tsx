import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    ArrowDown,
    ArrowUp,
    Copy,
    Eye,
    EyeOff,
    Layers,
    Plus,
    Trash2,
} from '@/components/ui/icons';
import {
    addLayer,
    addSlide,
    CANVAS_PRESETS,
    createLayer,
    duplicateLayer,
    duplicateSlide,
    removeLayer,
    removeSlide,
    reorderLayer,
    reorderSlide,
    resizeDocument,
    updateLayer,
    updateSlide,
} from '@/lib/creator';
import type {
    CanvasPreset,
    CreatorDocument,
    CreatorLayer,
    CreatorPoint,
} from '@/lib/creator';
import { cn } from '@/lib/utils';
import type { CreatorAssetView } from '@/types/creator';

import { CreatorCanvas } from './creator-canvas';
import {
    CreatorColor,
    CreatorField,
    creatorInputClass,
} from './creator-controls';
import { CreatorLayerInspector } from './creator-layer-inspector';

type Props = {
    document: CreatorDocument;
    onChange: (
        document:
            | CreatorDocument
            | ((current: CreatorDocument) => CreatorDocument),
    ) => void;
    assets: CreatorAssetView[];
    loadAsset: (id: string) => Promise<HTMLImageElement>;
    onUpload: (file: File, kind: 'image' | 'logo') => Promise<CreatorAssetView>;
    onSelectedAssetChange: (id: string | null) => void;
    onActiveSlideChange: (id: string) => void;
    activeSlideId: string;
    onBrowseAssets: (search: string, more?: boolean) => Promise<void>;
    hasMoreAssets: boolean;
    assetQuery: string;
    brandColors?: string[];
    generationPanel: ReactNode;
    libraryPanel?: ReactNode;
    disabled?: boolean;
};

export function CreatorEditor({
    document: scene,
    onChange,
    assets,
    loadAsset,
    onUpload,
    onSelectedAssetChange,
    onActiveSlideChange,
    activeSlideId,
    onBrowseAssets,
    hasMoreAssets,
    assetQuery,
    brandColors = [],
    generationPanel,
    libraryPanel,
    disabled = false,
}: Props) {
    const slideId = activeSlideId;
    const setSlideId = onActiveSlideChange;
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [panel, setPanel] = useState<'design' | 'assets' | 'ai' | 'library'>(
        'design',
    );
    const [tool, setTool] = useState<'select' | 'pen' | 'eraser'>('select');
    const [brushColor, setBrushColor] = useState('#ffffff');
    const [brushWidth, setBrushWidth] = useState(12);
    const [assetSearch, setAssetSearch] = useState('');
    const [uploading, setUploading] = useState(false);
    const photoInput = useRef<HTMLInputElement>(null);
    const logoInput = useRef<HTMLInputElement>(null);
    const mounted = useRef(true);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    const slide =
        scene.slides.find((item) => item.id === slideId) ?? scene.slides[0];
    const selected = slide.layers.find((item) => item.id === selectedId);

    useEffect(() => {
        onSelectedAssetChange(
            selected?.type === 'image' ? selected.asset_id : null,
        );
    }, [selected, onSelectedAssetChange]);
    useEffect(() => {
        if (slide.id !== activeSlideId) onActiveSlideChange(slide.id);
    }, [slide.id, activeSlideId, onActiveSlideChange]);

    function change(next: () => CreatorDocument) {
        if (disabled) return;
        try {
            onChange(next());
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not update the design.',
            );
        }
    }

    function insert(layer: CreatorLayer) {
        change(() => addLayer(scene, slide.id, layer));
        setSelectedId(layer.id);
        setTool('select');
    }

    function insertAsset(asset: CreatorAssetView) {
        const logo = asset.kind === 'logo';
        insert(
            createLayer('image', {
                name: asset.name,
                asset_id: asset.id,
                role: logo ? 'logo' : undefined,
                x: logo ? scene.canvas.width - 300 : 0,
                y: logo ? 60 : 0,
                width: logo ? 240 : scene.canvas.width,
                height: logo ? 180 : scene.canvas.height,
                fit: logo ? 'contain' : 'cover',
            }),
        );
    }

    async function upload(file: File | undefined, kind: 'image' | 'logo') {
        if (!file || uploading || disabled) return;
        if (
            !['image/png', 'image/jpeg', 'image/webp'].includes(file.type) ||
            file.size > 8 * 1024 * 1024
        ) {
            toast.error('Choose a PNG, JPEG or WebP image under 8 MB.');
            return;
        }
        setUploading(true);
        const destinationSlide = slide.id;
        try {
            const asset = await onUpload(file, kind);
            if (!mounted.current) return;
            const layerId = crypto.randomUUID();
            onChange((current) => {
                if (
                    !current.slides.some((item) => item.id === destinationSlide)
                )
                    return current;
                const logo = asset.kind === 'logo';
                return addLayer(
                    current,
                    destinationSlide,
                    createLayer('image', {
                        id: layerId,
                        name: asset.name,
                        asset_id: asset.id,
                        role: logo ? 'logo' : undefined,
                        x: logo ? current.canvas.width - 300 : 0,
                        y: logo ? 60 : 0,
                        width: logo ? 240 : current.canvas.width,
                        height: logo ? 180 : current.canvas.height,
                        fit: logo ? 'contain' : 'cover',
                    }),
                );
            });
            setSelectedId(layerId);
            setTool('select');
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not upload this asset.',
            );
        } finally {
            if (mounted.current) setUploading(false);
        }
    }

    function draw(points: CreatorPoint[], strokeTool: 'pen' | 'eraser') {
        if (disabled) return;
        if (strokeTool === 'eraser' && selected?.type !== 'drawing') {
            toast.error('Select a drawing layer before using the eraser.');
            return;
        }
        const drawing =
            selected?.type === 'drawing' && !selected.locked
                ? selected
                : createLayer('drawing', {
                      name: 'Drawing',
                      x: 0,
                      y: 0,
                      width: scene.canvas.width,
                      height: scene.canvas.height,
                  });
        if (selected?.type === 'drawing' && selected.locked) {
            toast.error('Unlock this drawing layer first.');
            return;
        }
        const angle = (-drawing.rotation * Math.PI) / 180;
        const localPoints = points.map((point) => {
            const dx = point.x - drawing.x - drawing.width / 2;
            const dy = point.y - drawing.y - drawing.height / 2;
            return {
                x:
                    dx * Math.cos(angle) -
                    dy * Math.sin(angle) +
                    drawing.width / 2,
                y:
                    dx * Math.sin(angle) +
                    dy * Math.cos(angle) +
                    drawing.height / 2,
            };
        });
        const updated = {
            ...drawing,
            strokes: [
                ...drawing.strokes,
                {
                    tool: strokeTool,
                    color: brushColor,
                    width: brushWidth,
                    points: localPoints,
                },
            ],
        };
        change(() =>
            selected?.id === drawing.id
                ? updateLayer(scene, slide.id, drawing.id, updated)
                : addLayer(scene, slide.id, updated),
        );
        setSelectedId(drawing.id);
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
            <div className="flex shrink-0 flex-wrap items-center gap-2 border-y border-border bg-muted/30 px-4 py-2">
                <CreatorField label="Output size" className="min-w-44">
                    <select
                        aria-label="Output size"
                        className={creatorInputClass}
                        disabled={disabled}
                        value={
                            Object.entries(CANVAS_PRESETS).find(
                                ([, value]) =>
                                    value.width === scene.canvas.width &&
                                    value.height === scene.canvas.height,
                            )?.[0] ?? 'custom'
                        }
                        onChange={(event) => {
                            const preset =
                                CANVAS_PRESETS[
                                    event.currentTarget.value as CanvasPreset
                                ];
                            if (preset)
                                change(() => resizeDocument(scene, preset));
                        }}
                    >
                        <option value="custom" disabled>
                            Custom size
                        </option>
                        {Object.entries(CANVAS_PRESETS).map(([key, value]) => (
                            <option key={key} value={key}>
                                {value.label}
                            </option>
                        ))}
                    </select>
                </CreatorField>
                <div
                    className="ml-auto flex items-center gap-1"
                    role="group"
                    aria-label="Canvas tool"
                >
                    {(['select', 'pen', 'eraser'] as const).map((item) => (
                        <Button
                            key={item}
                            variant={tool === item ? 'secondary' : 'ghost'}
                            size="sm"
                            disabled={disabled}
                            onClick={() => setTool(item)}
                        >
                            {item === 'select'
                                ? 'Move'
                                : item === 'pen'
                                  ? 'Brush'
                                  : 'Eraser'}
                        </Button>
                    ))}
                </div>
                {tool !== 'select' && (
                    <>
                        <input
                            type="color"
                            aria-label="Brush color"
                            value={brushColor}
                            onChange={(event) =>
                                setBrushColor(event.currentTarget.value)
                            }
                            className="h-7 w-8"
                        />
                        <input
                            aria-label="Brush width"
                            type="number"
                            min={1}
                            max={200}
                            value={brushWidth}
                            onChange={(event) =>
                                setBrushWidth(
                                    Math.max(
                                        1,
                                        Math.min(
                                            200,
                                            Number(event.currentTarget.value) ||
                                                1,
                                        ),
                                    ),
                                )
                            }
                            className={cn(creatorInputClass, 'w-16')}
                        />
                    </>
                )}
            </div>
            <div className="grid min-h-0 flex-1 grid-cols-1 overflow-auto lg:grid-cols-[260px_minmax(0,1fr)_250px] lg:overflow-hidden">
                <aside className="flex min-h-0 flex-col border-b border-border bg-background lg:border-r lg:border-b-0">
                    <div
                        className="grid grid-cols-4 gap-1 border-b border-border p-2"
                        role="tablist"
                        aria-label="Creator tools"
                    >
                        {(['design', 'assets', 'ai', 'library'] as const).map(
                            (item) => (
                                <button
                                    key={item}
                                    type="button"
                                    role="tab"
                                    aria-selected={panel === item}
                                    aria-controls={`creator-panel-${item}`}
                                    className={cn(
                                        'rounded-lg px-1 py-2 text-xs font-medium capitalize',
                                        panel === item
                                            ? 'bg-secondary text-foreground'
                                            : 'text-muted-foreground hover:bg-muted',
                                    )}
                                    onClick={() => setPanel(item)}
                                >
                                    {item === 'ai' ? 'AI tools' : item}
                                </button>
                            ),
                        )}
                    </div>
                    <div
                        className="max-h-72 overflow-y-auto lg:max-h-none lg:flex-1"
                        role="tabpanel"
                        id={`creator-panel-${panel}`}
                    >
                        {panel === 'ai' && generationPanel}
                        {panel === 'library' &&
                            (libraryPanel ?? (
                                <p className="p-4 text-sm text-muted-foreground">
                                    Your saved projects remain available when
                                    you reopen Creator.
                                </p>
                            ))}
                        {panel === 'assets' && (
                            <div className="flex flex-col gap-3 p-3">
                                <input
                                    aria-label="Search company assets"
                                    placeholder="Search company assets…"
                                    value={assetSearch}
                                    onChange={(event) =>
                                        setAssetSearch(
                                            event.currentTarget.value,
                                        )
                                    }
                                    className={creatorInputClass}
                                />
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={disabled}
                                    onClick={() => {
                                        void onBrowseAssets(assetSearch);
                                    }}
                                >
                                    Search entire company library
                                </Button>
                                <div className="grid grid-cols-2 gap-2">
                                    <Button
                                        variant="outline"
                                        disabled={uploading || disabled}
                                        onClick={() =>
                                            photoInput.current?.click()
                                        }
                                    >
                                        Upload photo
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={uploading || disabled}
                                        onClick={() =>
                                            logoInput.current?.click()
                                        }
                                    >
                                        Upload logo
                                    </Button>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    PNG, JPEG or WebP · up to 8 MB. Transparent
                                    PNG works best for logos.
                                </p>
                                <div className="grid grid-cols-2 gap-2">
                                    {assets
                                        .filter((asset) =>
                                            asset.name
                                                .toLowerCase()
                                                .includes(
                                                    assetSearch.toLowerCase(),
                                                ),
                                        )
                                        .map((asset) => (
                                            <button
                                                key={asset.id}
                                                type="button"
                                                disabled={disabled}
                                                className="overflow-hidden rounded-xl border border-border bg-muted/40 p-1 text-left hover:border-primary"
                                                onClick={() =>
                                                    insertAsset(asset)
                                                }
                                            >
                                                <img
                                                    src={asset.content_url}
                                                    alt={asset.name}
                                                    loading="lazy"
                                                    className="aspect-square w-full rounded-lg object-contain"
                                                />
                                                <span className="block truncate px-1 pt-1 text-xs">
                                                    {asset.name}
                                                </span>
                                                <span className="px-1 text-[10px] text-muted-foreground">
                                                    {asset.kind}
                                                </span>
                                            </button>
                                        ))}
                                </div>
                                {assets.length === 0 && (
                                    <p className="py-5 text-center text-sm text-muted-foreground">
                                        Upload your first photo or logo to start
                                        composing.
                                    </p>
                                )}
                                {hasMoreAssets &&
                                    assetQuery === assetSearch && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={disabled}
                                            onClick={() => {
                                                void onBrowseAssets(
                                                    assetSearch,
                                                    true,
                                                );
                                            }}
                                        >
                                            Load more assets
                                        </Button>
                                    )}
                            </div>
                        )}
                        {panel === 'design' && (
                            <div className="flex flex-col gap-4 p-3">
                                <div className="grid grid-cols-2 gap-2">
                                    <Button
                                        variant="outline"
                                        disabled={disabled}
                                        onClick={() =>
                                            insert(
                                                createLayer('text', {
                                                    name: 'Headline',
                                                    text: 'Your headline',
                                                    x: 80,
                                                    y: 100,
                                                    width:
                                                        scene.canvas.width -
                                                        160,
                                                    height: 220,
                                                    font_size: 76,
                                                }),
                                            )
                                        }
                                    >
                                        Add text
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={disabled}
                                        onClick={() =>
                                            insert(
                                                createLayer('shape', {
                                                    name: 'Brand shape',
                                                    x: 80,
                                                    y: 380,
                                                    width: 360,
                                                    height: 180,
                                                    fill:
                                                        brandColors[0] ??
                                                        '#6366f1',
                                                    radius: 24,
                                                }),
                                            )
                                        }
                                    >
                                        Add shape
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={disabled || uploading}
                                        onClick={() =>
                                            photoInput.current?.click()
                                        }
                                    >
                                        Add photo
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={disabled || uploading}
                                        onClick={() =>
                                            logoInput.current?.click()
                                        }
                                    >
                                        Add logo
                                    </Button>
                                </div>
                                <CreatorField label="Slide name">
                                    <input
                                        className={creatorInputClass}
                                        value={slide.name}
                                        maxLength={120}
                                        onChange={(event) =>
                                            change(() =>
                                                updateSlide(scene, slide.id, {
                                                    name: event.currentTarget
                                                        .value,
                                                }),
                                            )
                                        }
                                    />
                                </CreatorField>
                                <CreatorColor
                                    label="Background color"
                                    value={slide.background_color}
                                    onChange={(background_color) =>
                                        change(() =>
                                            updateSlide(scene, slide.id, {
                                                background_color,
                                            }),
                                        )
                                    }
                                />
                                <label className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <input
                                        type="checkbox"
                                        checked={
                                            slide.background_color ===
                                            '#00000000'
                                        }
                                        disabled={disabled}
                                        onChange={(event) =>
                                            change(() =>
                                                updateSlide(scene, slide.id, {
                                                    background_color: event
                                                        .currentTarget.checked
                                                        ? '#00000000'
                                                        : '#171717',
                                                }),
                                            )
                                        }
                                    />
                                    Transparent background (PNG / WebP)
                                </label>
                                <div className="flex items-center justify-between border-t border-border pt-3">
                                    <h3 className="flex items-center gap-2 text-sm font-semibold">
                                        <Layers className="size-4" />
                                        Layers
                                    </h3>
                                    <span className="text-xs text-muted-foreground">
                                        Top first
                                    </span>
                                </div>
                                {slide.layers.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        Start with text, your photo, or a prompt
                                        in AI tools. Every element stays
                                        editable.
                                    </p>
                                )}
                                <div className="flex flex-col gap-1">
                                    {[...slide.layers]
                                        .reverse()
                                        .map((layer) => (
                                            <div
                                                key={layer.id}
                                                className={cn(
                                                    'flex items-center gap-1 rounded-lg border p-1',
                                                    selectedId === layer.id
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-transparent hover:bg-muted',
                                                )}
                                            >
                                                <button
                                                    type="button"
                                                    className="min-w-0 flex-1 px-2 py-2 text-left text-xs"
                                                    onClick={() => {
                                                        setSelectedId(layer.id);
                                                        setTool('select');
                                                    }}
                                                >
                                                    <span className="block truncate font-medium">
                                                        {layer.name}
                                                    </span>
                                                    <span className="text-[10px] text-muted-foreground">
                                                        {layer.type}
                                                        {layer.locked
                                                            ? ' · locked'
                                                            : ''}
                                                    </span>
                                                </button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-xs"
                                                    aria-label={`${layer.visible ? 'Hide' : 'Show'} ${layer.name}`}
                                                    disabled={disabled}
                                                    onClick={() =>
                                                        change(() =>
                                                            updateLayer(
                                                                scene,
                                                                slide.id,
                                                                layer.id,
                                                                {
                                                                    visible:
                                                                        !layer.visible,
                                                                },
                                                            ),
                                                        )
                                                    }
                                                >
                                                    {layer.visible ? (
                                                        <Eye />
                                                    ) : (
                                                        <EyeOff />
                                                    )}
                                                </Button>
                                            </div>
                                        ))}
                                </div>
                                {selected && (
                                    <div className="flex items-center gap-1 border-t border-border pt-2">
                                        <Button
                                            variant="outline"
                                            size="icon-sm"
                                            aria-label="Bring layer forward"
                                            disabled={
                                                disabled ||
                                                slide.layers.at(-1)?.id ===
                                                    selected.id
                                            }
                                            onClick={() =>
                                                change(() =>
                                                    reorderLayer(
                                                        scene,
                                                        slide.id,
                                                        selected.id,
                                                        slide.layers.findIndex(
                                                            (layer) =>
                                                                layer.id ===
                                                                selected.id,
                                                        ) + 1,
                                                    ),
                                                )
                                            }
                                        >
                                            <ArrowUp />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="icon-sm"
                                            aria-label="Send layer backward"
                                            disabled={
                                                disabled ||
                                                slide.layers[0]?.id ===
                                                    selected.id
                                            }
                                            onClick={() =>
                                                change(() =>
                                                    reorderLayer(
                                                        scene,
                                                        slide.id,
                                                        selected.id,
                                                        slide.layers.findIndex(
                                                            (layer) =>
                                                                layer.id ===
                                                                selected.id,
                                                        ) - 1,
                                                    ),
                                                )
                                            }
                                        >
                                            <ArrowDown />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="icon-sm"
                                            aria-label="Duplicate layer"
                                            disabled={disabled}
                                            onClick={() =>
                                                change(() =>
                                                    duplicateLayer(
                                                        scene,
                                                        slide.id,
                                                        selected.id,
                                                    ),
                                                )
                                            }
                                        >
                                            <Copy />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label="Delete layer"
                                            disabled={disabled}
                                            onClick={() =>
                                                change(() =>
                                                    removeLayer(
                                                        scene,
                                                        slide.id,
                                                        selected.id,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </aside>
                <div className="flex min-w-0 flex-col">
                    <CreatorCanvas
                        document={scene}
                        slideId={slide.id}
                        selectedId={selectedId}
                        loadAsset={loadAsset}
                        onSelect={setSelectedId}
                        onTransform={(layer) =>
                            change(() =>
                                updateLayer(scene, slide.id, layer.id, layer),
                            )
                        }
                        tool={tool}
                        onStroke={draw}
                    />
                </div>
                <aside className="min-h-0 overflow-y-auto border-t border-border bg-background lg:border-t-0 lg:border-l">
                    <CreatorLayerInspector
                        layer={selected}
                        brandColors={brandColors}
                        onChange={(layer) =>
                            change(() =>
                                updateLayer(scene, slide.id, layer.id, layer),
                            )
                        }
                    />
                </aside>
            </div>
            <div
                className="flex shrink-0 items-center gap-2 overflow-x-auto border-t border-border bg-background p-3"
                aria-label="Carousel slides"
            >
                {scene.slides.map((item, index) => (
                    <button
                        key={item.id}
                        type="button"
                        aria-label={`Select slide ${index + 1}: ${item.name}`}
                        aria-pressed={slide.id === item.id}
                        className={cn(
                            'flex h-12 shrink-0 items-center gap-2 rounded-lg border px-3 text-xs',
                            slide.id === item.id
                                ? 'border-primary bg-primary/5'
                                : 'border-border hover:bg-muted',
                        )}
                        onClick={() => {
                            setSlideId(item.id);
                            setSelectedId(null);
                        }}
                    >
                        <span className="flex size-7 items-center justify-center rounded bg-muted font-mono">
                            {index + 1}
                        </span>
                        <span className="max-w-24 truncate">{item.name}</span>
                    </button>
                ))}
                <Button
                    variant="outline"
                    size="icon-sm"
                    aria-label="Add slide"
                    disabled={disabled || scene.slides.length >= 10}
                    onClick={() => {
                        const next = addSlide(scene);
                        change(() => next);
                        setSlideId(next.slides.at(-1)!.id);
                        setSelectedId(null);
                    }}
                >
                    <Plus />
                </Button>
                <div className="ml-auto flex shrink-0 gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={disabled || scene.slides.length >= 10}
                        onClick={() =>
                            change(() => duplicateSlide(scene, slide.id))
                        }
                    >
                        Duplicate slide
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={disabled || scene.slides.length === 1}
                        onClick={() =>
                            change(() => removeSlide(scene, slide.id))
                        }
                    >
                        Remove
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Move slide earlier"
                        disabled={disabled || scene.slides[0].id === slide.id}
                        onClick={() =>
                            change(() =>
                                reorderSlide(
                                    scene,
                                    slide.id,
                                    scene.slides.findIndex(
                                        (item) => item.id === slide.id,
                                    ) - 1,
                                ),
                            )
                        }
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Move slide later"
                        disabled={
                            disabled || scene.slides.at(-1)?.id === slide.id
                        }
                        onClick={() =>
                            change(() =>
                                reorderSlide(
                                    scene,
                                    slide.id,
                                    scene.slides.findIndex(
                                        (item) => item.id === slide.id,
                                    ) + 1,
                                ),
                            )
                        }
                    >
                        <ArrowDown />
                    </Button>
                </div>
            </div>
            <input
                ref={photoInput}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                hidden
                onChange={(event) => {
                    void upload(event.currentTarget.files?.[0], 'image');
                    event.currentTarget.value = '';
                }}
            />
            <input
                ref={logoInput}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                hidden
                onChange={(event) => {
                    void upload(event.currentTarget.files?.[0], 'logo');
                    event.currentTarget.value = '';
                }}
            />
        </div>
    );
}
