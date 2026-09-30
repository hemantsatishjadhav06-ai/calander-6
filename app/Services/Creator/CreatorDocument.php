<?php

declare(strict_types=1);

namespace App\Services\Creator;

use App\Models\CreatorAsset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

final class CreatorDocument
{
    private const string COLOR = '/^#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/';

    private const string IDENTIFIER = '/^[a-zA-Z0-9_-]{1,100}$/';

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed> */
    public static function validate(array $document, string $workspaceId): array
    {
        if (strlen(json_encode($document, JSON_THROW_ON_ERROR)) > 2_000_000) {
            throw ValidationException::withMessages(['document' => 'The design document is too large.']);
        }
        $rules = [
            'schema_version' => ['required', 'integer:strict', Rule::in([1])],
            'canvas' => ['required', 'array:width,height'],
            'canvas.width' => ['required', 'integer:strict', 'between:1,4096'],
            'canvas.height' => ['required', 'integer:strict', 'between:1,4096'],
            'slides' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'slides.*' => ['required', 'array:id,name,background_color,layers'],
            'slides.*.id' => ['required', 'string', 'regex:'.self::IDENTIFIER, 'distinct:strict'],
            'slides.*.name' => ['sometimes', 'string', 'max:200'],
            'slides.*.background_color' => ['required', 'string', 'regex:'.self::COLOR],
            'slides.*.layers' => ['present', 'array', 'list', 'max:64'],
        ];
        foreach (is_array($document['slides'] ?? null) ? $document['slides'] : [] as $slideIndex => $slide) {
            if (! is_array($slide)) {
                continue;
            }
            foreach (is_array($slide['layers'] ?? null) ? $slide['layers'] : [] as $layerIndex => $layer) {
                if (! is_array($layer)) {
                    $rules["slides.{$slideIndex}.layers.{$layerIndex}"] = ['array'];

                    continue;
                }
                $prefix = "slides.{$slideIndex}.layers.{$layerIndex}";
                $type = is_string($layer['type'] ?? null) ? $layer['type'] : '';
                $base = 'id,type,name,visible,locked,x,y,width,height,rotation,opacity';
                $extra = match ($type) {
                    'text' => ',text,font_family,font_size,font_weight,color,text_align',
                    'image' => ',asset_id,fit,crop,grade,role',
                    'shape' => ',shape,fill,radius',
                    'drawing' => ',strokes',
                    default => '',
                };
                $rules[$prefix] = ['required', 'array:'.$base.$extra];
                foreach (self::layerRules($type) as $field => $rule) {
                    $rules[$prefix.'.'.$field] = array_map(static fn (mixed $value): mixed => $value === 'required_with:crop' ? 'required_with:'.$prefix.'.crop' : $value, $rule);
                }
            }
        }
        $validator = Validator::make($document, $rules);
        $validator->after(function (LaravelValidator $validator) use ($document, $workspaceId): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($document['canvas']['width'] * $document['canvas']['height'] > (int) config('media.max_image_pixels', 16_000_000)) {
                $validator->errors()->add('canvas', 'The canvas exceeds the image-resolution limit.');
            }
            $assetIds = [];
            $strokes = 0;
            $points = 0;
            $ids = [];
            foreach ($document['slides'] as $slideIndex => $slide) {
                if (in_array($slide['id'], $ids, true)) {
                    $validator->errors()->add('slides.'.$slideIndex.'.id', 'Scene IDs must be unique.');
                }
                $ids[] = $slide['id'];
                foreach ($slide['layers'] as $layerIndex => $layer) {
                    $prefix = "slides.{$slideIndex}.layers.{$layerIndex}";
                    if (in_array($layer['id'], $ids, true)) {
                        $validator->errors()->add($prefix.'.id', 'Scene IDs must be unique.');
                    }
                    $ids[] = $layer['id'];
                    if ($layer['type'] === 'image') {
                        $assetIds[] = $layer['asset_id'];
                        $crop = $layer['crop'] ?? null;
                        if ($crop !== null && ($crop['x'] + $crop['width'] > 1.000000001 || $crop['y'] + $crop['height'] > 1.000000001)) {
                            $validator->errors()->add($prefix.'.crop', 'The crop must fit inside the asset.');
                        }
                    }
                    foreach ($layer['strokes'] ?? [] as $stroke) {
                        $strokes++;
                        $points += count($stroke['points']);
                    }
                }
            }
            if ($strokes > 200 || $points > 5000) {
                $validator->errors()->add('slides', 'A design supports at most 200 strokes and 5000 drawing points.');
            }
            $assetIds = array_values(array_unique($assetIds));
            $found = CreatorAsset::withoutGlobalScopes()->where('workspace_id', $workspaceId)->whereIn('id', $assetIds)->count();
            if ($found !== count($assetIds)) {
                $validator->errors()->add('slides', 'Every image must be a saved asset in this workspace.');
            }
        });
        if (array_diff(array_keys($document), ['schema_version', 'canvas', 'slides']) !== []) {
            throw ValidationException::withMessages(['document' => 'The design contains unsupported fields.']);
        }

        return $validator->validate();
    }

    /**
     * @return array<string, array<mixed>> */
    private static function layerRules(string $type): array
    {
        $rules = [
            'id' => ['required', 'string', 'regex:'.self::IDENTIFIER],
            'type' => ['required', Rule::in(['text', 'image', 'shape', 'drawing'])],
            'name' => ['sometimes', 'string', 'max:200'],
            'visible' => ['sometimes', 'boolean:strict'],
            'locked' => ['sometimes', 'boolean:strict'],
            'x' => ['required', 'numeric:strict', 'between:-16384,16384'],
            'y' => ['required', 'numeric:strict', 'between:-16384,16384'],
            'width' => ['required', 'numeric:strict', 'between:1,16384'],
            'height' => ['required', 'numeric:strict', 'between:1,16384'],
            'rotation' => ['sometimes', 'numeric:strict', 'between:-360,360'],
            'opacity' => ['sometimes', 'numeric:strict', 'between:0,1'],
        ];

        return [...$rules, ...match ($type) {
            'text' => [
                'text' => ['present', 'string', 'max:20000'],
                'font_family' => ['required', Rule::in(['Arial', 'Georgia', 'Courier New'])],
                'font_size' => ['required', 'numeric:strict', 'between:1,1000'],
                'font_weight' => ['required', 'integer:strict', Rule::in([400, 700])],
                'color' => ['required', 'string', 'regex:'.self::COLOR],
                'text_align' => ['required', Rule::in(['left', 'center', 'right'])],
            ],
            'image' => [
                'asset_id' => ['required', 'uuid'],
                'fit' => ['required', Rule::in(['contain', 'cover'])],
                'role' => ['sometimes', Rule::in(['logo'])],
                'crop' => ['sometimes', 'array:x,y,width,height'],
                'crop.x' => ['required_with:crop', 'numeric:strict', 'between:0,0.999999'],
                'crop.y' => ['required_with:crop', 'numeric:strict', 'between:0,0.999999'],
                'crop.width' => ['required_with:crop', 'numeric:strict', 'between:0.000001,1'],
                'crop.height' => ['required_with:crop', 'numeric:strict', 'between:0.000001,1'],
                'grade' => ['sometimes', 'array:exposure,contrast,saturation,hue,warmth,vignette'],
                'grade.exposure' => ['sometimes', 'numeric:strict', 'between:-2,2'],
                'grade.contrast' => ['sometimes', 'numeric:strict', 'between:-100,100'],
                'grade.saturation' => ['sometimes', 'numeric:strict', 'between:-100,100'],
                'grade.hue' => ['sometimes', 'numeric:strict', 'between:-180,180'],
                'grade.warmth' => ['sometimes', 'numeric:strict', 'between:-100,100'],
                'grade.vignette' => ['sometimes', 'numeric:strict', 'between:0,100'],
            ],
            'shape' => [
                'shape' => ['required', Rule::in(['rect', 'ellipse'])],
                'fill' => ['required', 'string', 'regex:'.self::COLOR],
                'radius' => ['sometimes', 'numeric:strict', 'between:0,8192'],
            ],
            'drawing' => [
                'strokes' => ['present', 'array', 'list', 'max:200'],
                'strokes.*' => ['array:tool,points,color,width'],
                'strokes.*.tool' => ['required', Rule::in(['pen', 'eraser'])],
                'strokes.*.points' => ['required', 'array', 'list', 'min:1', 'max:5000'],
                'strokes.*.points.*' => ['array:x,y'],
                'strokes.*.points.*.x' => ['required', 'numeric:strict', 'between:-16384,16384'],
                'strokes.*.points.*.y' => ['required', 'numeric:strict', 'between:-16384,16384'],
                'strokes.*.color' => ['required', 'string', 'regex:'.self::COLOR],
                'strokes.*.width' => ['required', 'numeric:strict', 'between:0.5,1000'],
            ],
            default => [],
        }];
    }

    /**
     * @param array<string, mixed> $document */
    public static function hash(array $document): string
    {
        return hash('sha256', json_encode(self::canonical($document), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }

        return $value;
    }
}
