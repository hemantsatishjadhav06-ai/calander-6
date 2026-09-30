import { Button } from '@/components/ui/button';
import type { CreatorLayer } from '@/lib/creator';

import {
    CreatorColor,
    CreatorField,
    creatorInputClass,
    CreatorNumber,
} from './creator-controls';

type Props = {
    layer: CreatorLayer | undefined;
    onChange: (layer: CreatorLayer) => void;
    brandColors: string[];
};

export function CreatorLayerInspector({ layer, onChange, brandColors }: Props) {
    if (!layer)
        return (
            <p className="p-4 text-sm text-muted-foreground">
                Select a layer to edit its text, placement, appearance or photo
                grading.
            </p>
        );
    return (
        <div className="flex flex-col gap-4 p-4">
            <div className="flex items-center justify-between">
                <h3 className="font-semibold">Layer properties</h3>
                <span className="rounded bg-muted px-2 py-1 text-xs capitalize">
                    {layer.type}
                </span>
            </div>
            <CreatorField label="Layer name">
                <input
                    className={creatorInputClass}
                    value={layer.name}
                    maxLength={120}
                    onChange={(event) =>
                        onChange({ ...layer, name: event.currentTarget.value })
                    }
                />
            </CreatorField>
            <div className="flex gap-3 text-xs">
                <label className="flex items-center gap-1.5">
                    <input
                        type="checkbox"
                        checked={layer.visible}
                        onChange={(event) =>
                            onChange({
                                ...layer,
                                visible: event.currentTarget.checked,
                            })
                        }
                    />
                    Visible
                </label>
                <label className="flex items-center gap-1.5">
                    <input
                        type="checkbox"
                        checked={layer.locked}
                        onChange={(event) =>
                            onChange({
                                ...layer,
                                locked: event.currentTarget.checked,
                            })
                        }
                    />
                    Lock position
                </label>
            </div>
            <div className="grid grid-cols-2 gap-2">
                <CreatorNumber
                    label="X position"
                    value={layer.x}
                    min={-8192}
                    max={8192}
                    disabled={layer.locked}
                    onChange={(x) => onChange({ ...layer, x })}
                />
                <CreatorNumber
                    label="Y position"
                    value={layer.y}
                    min={-8192}
                    max={8192}
                    disabled={layer.locked}
                    onChange={(y) => onChange({ ...layer, y })}
                />
                <CreatorNumber
                    label="Width"
                    value={layer.width}
                    min={8}
                    max={8192}
                    disabled={layer.locked || layer.type === 'drawing'}
                    onChange={(width) => onChange({ ...layer, width })}
                />
                <CreatorNumber
                    label="Height"
                    value={layer.height}
                    min={8}
                    max={8192}
                    disabled={layer.locked || layer.type === 'drawing'}
                    onChange={(height) => onChange({ ...layer, height })}
                />
                <CreatorNumber
                    label="Rotation"
                    value={layer.rotation}
                    min={-180}
                    max={180}
                    disabled={layer.locked}
                    onChange={(rotation) => onChange({ ...layer, rotation })}
                />
                <CreatorNumber
                    label="Opacity %"
                    value={layer.opacity * 100}
                    min={0}
                    max={100}
                    onChange={(opacity) =>
                        onChange({ ...layer, opacity: opacity / 100 })
                    }
                />
            </div>
            {layer.type === 'text' && (
                <>
                    <CreatorField label="Editable text">
                        <textarea
                            className={creatorInputClass}
                            rows={4}
                            value={layer.text}
                            maxLength={5000}
                            onChange={(event) =>
                                onChange({
                                    ...layer,
                                    text: event.currentTarget.value,
                                })
                            }
                        />
                    </CreatorField>
                    <CreatorField label="Font">
                        <select
                            className={creatorInputClass}
                            value={layer.font_family}
                            onChange={(event) =>
                                onChange({
                                    ...layer,
                                    font_family: event.currentTarget
                                        .value as typeof layer.font_family,
                                })
                            }
                        >
                            <option>Arial</option>
                            <option>Georgia</option>
                            <option>Courier New</option>
                        </select>
                    </CreatorField>
                    <div className="grid grid-cols-2 gap-2">
                        <CreatorNumber
                            label="Font size"
                            value={layer.font_size}
                            min={8}
                            max={512}
                            onChange={(font_size) =>
                                onChange({ ...layer, font_size })
                            }
                        />
                        <CreatorField label="Weight">
                            <select
                                className={creatorInputClass}
                                value={layer.font_weight}
                                onChange={(event) =>
                                    onChange({
                                        ...layer,
                                        font_weight: Number(
                                            event.currentTarget.value,
                                        ) as 400 | 700,
                                    })
                                }
                            >
                                <option value={400}>Regular</option>
                                <option value={700}>Bold</option>
                            </select>
                        </CreatorField>
                    </div>
                    <CreatorField label="Text alignment">
                        <select
                            className={creatorInputClass}
                            value={layer.text_align}
                            onChange={(event) =>
                                onChange({
                                    ...layer,
                                    text_align: event.currentTarget
                                        .value as typeof layer.text_align,
                                })
                            }
                        >
                            <option value="left">Left</option>
                            <option value="center">Center</option>
                            <option value="right">Right</option>
                        </select>
                    </CreatorField>
                    <CreatorColor
                        label="Text color"
                        value={layer.color}
                        onChange={(color) => onChange({ ...layer, color })}
                    />
                </>
            )}
            {layer.type === 'shape' && (
                <>
                    <CreatorField label="Shape">
                        <select
                            className={creatorInputClass}
                            value={layer.shape}
                            onChange={(event) =>
                                onChange({
                                    ...layer,
                                    shape: event.currentTarget.value as
                                        | 'rect'
                                        | 'ellipse',
                                })
                            }
                        >
                            <option value="rect">Rectangle</option>
                            <option value="ellipse">Ellipse</option>
                        </select>
                    </CreatorField>
                    <CreatorColor
                        label="Fill color"
                        value={layer.fill}
                        onChange={(fill) => onChange({ ...layer, fill })}
                    />
                    {layer.shape === 'rect' && (
                        <CreatorNumber
                            label="Corner radius"
                            value={layer.radius}
                            min={0}
                            max={512}
                            onChange={(radius) =>
                                onChange({ ...layer, radius })
                            }
                        />
                    )}
                </>
            )}
            {(layer.type === 'text' || layer.type === 'shape') &&
                brandColors.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <span className="text-xs text-muted-foreground">
                            Company palette
                        </span>
                        <div className="flex flex-wrap gap-2">
                            {brandColors.map((color) => (
                                <button
                                    key={color}
                                    type="button"
                                    aria-label={`Use brand color ${color}`}
                                    className="size-7 rounded-full border border-border"
                                    style={{ backgroundColor: color }}
                                    onClick={() =>
                                        onChange(
                                            layer.type === 'text'
                                                ? { ...layer, color }
                                                : { ...layer, fill: color },
                                        )
                                    }
                                />
                            ))}
                        </div>
                    </div>
                )}
            {layer.type === 'image' && (
                <>
                    <CreatorField label="Image fit">
                        <select
                            className={creatorInputClass}
                            value={layer.fit}
                            onChange={(event) =>
                                onChange({
                                    ...layer,
                                    fit: event.currentTarget.value as
                                        | 'cover'
                                        | 'contain',
                                })
                            }
                        >
                            <option value="cover">Fill frame</option>
                            <option value="contain">
                                Fit full image / logo
                            </option>
                        </select>
                    </CreatorField>
                    <details className="rounded-lg border border-border p-3">
                        <summary className="cursor-pointer text-xs font-medium">
                            Source crop
                        </summary>
                        <div className="mt-3 grid grid-cols-2 gap-2">
                            {(['x', 'y', 'width', 'height'] as const).map(
                                (key) => (
                                    <CreatorNumber
                                        key={key}
                                        label={`Crop ${key} %`}
                                        value={
                                            (layer.crop?.[key] ??
                                                (key === 'width' ||
                                                key === 'height'
                                                    ? 1
                                                    : 0)) * 100
                                        }
                                        min={
                                            key === 'width' || key === 'height'
                                                ? 1
                                                : 0
                                        }
                                        max={100}
                                        onChange={(value) => {
                                            const crop = {
                                                x: 0,
                                                y: 0,
                                                width: 1,
                                                height: 1,
                                                ...layer.crop,
                                                [key]: value / 100,
                                            };
                                            crop.width = Math.min(
                                                crop.width,
                                                1 - crop.x,
                                            );
                                            crop.height = Math.min(
                                                crop.height,
                                                1 - crop.y,
                                            );
                                            if (
                                                crop.width > 0 &&
                                                crop.height > 0
                                            )
                                                onChange({ ...layer, crop });
                                        }}
                                    />
                                ),
                            )}
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="mt-2"
                            onClick={() =>
                                onChange({ ...layer, crop: undefined })
                            }
                        >
                            Reset crop
                        </Button>
                    </details>
                    <div className="flex items-center justify-between border-t border-border pt-3">
                        <h4 className="text-sm font-medium">Photo grading</h4>
                        <Button
                            variant="ghost"
                            size="xs"
                            onClick={() =>
                                onChange({
                                    ...layer,
                                    grade: {
                                        exposure: 0,
                                        contrast: 0,
                                        saturation: 0,
                                        hue: 0,
                                        warmth: 0,
                                        vignette: 0,
                                    },
                                })
                            }
                        >
                            Reset
                        </Button>
                    </div>
                    {(
                        [
                            {
                                key: 'exposure',
                                label: 'Exposure (stops)',
                                min: -2,
                                max: 2,
                                step: 0.1,
                            },
                            {
                                key: 'contrast',
                                label: 'Contrast',
                                min: -100,
                                max: 100,
                                step: 1,
                            },
                            {
                                key: 'saturation',
                                label: 'Saturation',
                                min: -100,
                                max: 100,
                                step: 1,
                            },
                            {
                                key: 'hue',
                                label: 'Hue',
                                min: -180,
                                max: 180,
                                step: 1,
                            },
                            {
                                key: 'warmth',
                                label: 'Warmth',
                                min: -100,
                                max: 100,
                                step: 1,
                            },
                            {
                                key: 'vignette',
                                label: 'Vignette',
                                min: 0,
                                max: 100,
                                step: 1,
                            },
                        ] as const
                    ).map(({ key, label, min, max, step }) => (
                        <CreatorField
                            key={key}
                            label={`${label}: ${layer.grade[key]}`}
                        >
                            <input
                                type="range"
                                min={min}
                                max={max}
                                step={step}
                                value={layer.grade[key]}
                                onChange={(event) =>
                                    onChange({
                                        ...layer,
                                        grade: {
                                            ...layer.grade,
                                            [key]: Number(
                                                event.currentTarget.value,
                                            ),
                                        },
                                    })
                                }
                            />
                        </CreatorField>
                    ))}
                </>
            )}
            {layer.type === 'drawing' && (
                <p className="text-xs text-muted-foreground">
                    Brush and eraser affect this drawing layer. Photo and text
                    layers remain separate. Use Undo to remove the last stroke.
                </p>
            )}
        </div>
    );
}
