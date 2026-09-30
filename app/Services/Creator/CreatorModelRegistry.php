<?php

declare(strict_types=1);

namespace App\Services\Creator;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Server-side operation catalog, following Aquora's catalog-only gateway boundary.
 * Adapted architecture: https://github.com/hemantsatishjadhav06-ai/open-generative-ai
 * Commit: 7e8a10a8a6c478d1d36435a1d292b0873a005eb3
 * Reference files: lib/gateway/catalog/catalog.json, generation.js, fal.js;
 * packages/studio/src/gateway.js. MIT notice: licenses/open-generative-ai-MIT.txt.
 * Endpoint schemas verified against fal.ai on 2026-09-30; no browser-supplied endpoints.
 */
final class CreatorModelRegistry
{
    private const array OPERATIONS = [
        'generate' => ['Generate image', 'fal-ai/nano-banana-pro', false],
        'edit' => ['Edit image', 'fal-ai/nano-banana-pro/edit', true],
        'enhance' => ['Enhance image', 'fal-ai/nano-banana-pro/edit', true],
        'relight' => ['Relight image', 'fal-ai/nano-banana-pro/edit', true],
        'angle' => ['Change camera angle', 'fal-ai/nano-banana-pro/edit', true],
        'text_edit' => ['Edit image text', 'fal-ai/nano-banana-pro/edit', true],
        'expand' => ['Expand image', 'fal-ai/nano-banana-pro/edit', true],
        'layerize' => ['Separate image layers', 'bytedance/seedream/v5/pro/layerize', true],
        'remove_background' => ['Remove background', 'fal-ai/birefnet/v2', true],
        'upscale' => ['Upscale image', 'fal-ai/esrgan', true],
    ];

    /** @return list<array{id: string, label: string, endpoint_id: string, requires_asset: bool}> */
    public function operations(): array
    {
        $operations = [];
        foreach (self::OPERATIONS as $id => [$label, $endpoint, $requiresAsset]) {
            $operations[] = ['id' => $id, 'label' => $label, 'endpoint_id' => $endpoint, 'requires_asset' => $requiresAsset];
        }

        return $operations;
    }

    public function endpoint(string $operation): string
    {
        if (! isset(self::OPERATIONS[$operation])) {
            throw ValidationException::withMessages(['operation' => 'Choose a supported Creator operation.']);
        }

        return self::OPERATIONS[$operation][1];
    }

    public function assertEndpoint(string $endpoint): void
    {
        if (! in_array($endpoint, array_column(self::OPERATIONS, 1), true)) {
            throw new CreatorProviderException('invalid_endpoint', 'This model is not available.', 422);
        }
    }

    /** @param array<string, mixed> $payload
     * @return array{operation: string, prompt: string, asset_id: ?string, options: array<string, mixed>, endpoint_id: string}
     */
    public function validate(array $payload): array
    {
        $operation = (string) ($payload['operation'] ?? '');
        if (isset($payload['prompt']) && is_string($payload['prompt'])) {
            $payload['prompt'] = trim($payload['prompt']);
        }
        $endpoint = $this->endpoint($operation);
        $needsAsset = self::OPERATIONS[$operation][2];
        $nano = str_starts_with($endpoint, 'fal-ai/nano-banana-pro');
        $optionRules = $nano ? [
            'aspect_ratio' => ['sometimes', Rule::in(['auto', '21:9', '16:9', '3:2', '4:3', '5:4', '1:1', '4:5', '3:4', '2:3', '9:16'])],
            'resolution' => ['sometimes', Rule::in(['1K', '2K', '4K'])],
            'num_images' => ['sometimes', 'integer', 'between:1,4'],
            'output_format' => ['sometimes', Rule::in(['png', 'jpeg', 'webp'])],
        ] : match ($operation) {
            'layerize' => ['image_size' => ['sometimes', Rule::in(['auto', 'auto_1K', 'auto_1.5K', 'auto_2K'])]],
            'upscale' => ['scale' => ['sometimes', 'numeric', 'between:1,8']],
            default => [],
        };
        $rules = [
            'operation' => ['required', 'string'],
            'prompt' => [$nano ? 'required' : 'nullable', 'string', $nano ? 'min:3' : 'min:0', 'max:10000'],
            'asset_id' => [$needsAsset ? 'required' : 'prohibited', 'uuid'],
            'options' => ['sometimes', 'array'.($optionRules === [] ? '' : ':'.implode(',', array_keys($optionRules)))],
        ];
        foreach ($optionRules as $key => $rule) {
            $rules['options.'.$key] = $rule;
        }
        $validated = Validator::make($payload, $rules)->validate();
        $options = $validated['options'] ?? [];
        if (array_diff(array_keys($options), array_keys($optionRules)) !== []) {
            throw ValidationException::withMessages(['options' => 'Unsupported model options.']);
        }
        $defaults = $nano ? ['aspect_ratio' => $needsAsset ? 'auto' : '1:1', 'resolution' => '1K', 'num_images' => 1, 'output_format' => 'png'] : match ($operation) {
            'layerize' => ['image_size' => 'auto'],
            'upscale' => ['scale' => 2],
            default => [],
        };

        $options = array_replace($defaults, $options);
        if (($options['resolution'] ?? null) === '4K' && (int) config('media.max_image_pixels', 16000000) < 20000000) {
            throw ValidationException::withMessages(['options.resolution' => '4K output can exceed the current image import limit. Choose 1K or 2K; a server administrator must explicitly raise the image pixel limit before enabling 4K.']);
        }
        if (isset($options['num_images'])) {
            $options['num_images'] = (int) $options['num_images'];
        }
        if (isset($options['scale'])) {
            $options['scale'] = (float) $options['scale'];
        }

        return ['operation' => $operation, 'endpoint_id' => $endpoint, 'prompt' => trim($validated['prompt'] ?? ''), 'asset_id' => $validated['asset_id'] ?? null, 'options' => $options];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function providerInput(array $input, ?string $ownedImageData): array
    {
        $operation = $input['operation'];
        $options = $input['options'];
        $this->assertEndpoint($input['endpoint_id']);
        if ($operation !== 'generate' && ($ownedImageData === null || ! preg_match('#^data:image/(png|jpeg|webp);base64,#', $ownedImageData))) {
            throw new CreatorProviderException('invalid_asset', 'Choose a stored Creator image.', 422);
        }
        if (str_starts_with($input['endpoint_id'], 'fal-ai/nano-banana-pro')) {
            return [...$options, 'prompt' => $input['prompt'], 'limit_generations' => true, 'enable_web_search' => false, ...($operation === 'generate' ? [] : ['image_urls' => [$ownedImageData]])];
        }
        if ($operation === 'layerize') {
            return [...$options, 'prompt' => $input['prompt'], 'image_url' => $ownedImageData, 'enable_safety_checker' => true];
        }

        return [...$options, 'image_url' => $ownedImageData];
    }
}
