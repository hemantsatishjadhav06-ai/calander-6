<?php

declare(strict_types=1);

namespace App\Services\Creator;

final class CreatorOutputNormalizer
{
    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    public function normalize(array $body, bool $layerize): array
    {
        $items = $layerize ? ($body['layers'] ?? []) : ($body['images'] ?? (isset($body['image']) ? [$body['image']] : []));
        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > ($layerize ? 17 : 4)) {
            throw new CreatorProviderException('invalid_output', 'The model did not return a supported image result.');
        }
        $normalized = [];
        $seenLayers = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new CreatorProviderException('invalid_output', 'The model returned malformed image metadata.');
            }
            $image = $layerize ? ($item['image'] ?? null) : $item;
            if (! is_array($image) || ! is_string($image['url'] ?? null)) {
                throw new CreatorProviderException('invalid_output', 'The model returned an incomplete image result.');
            }
            $output = ['url' => $image['url'], 'width' => $image['width'] ?? null, 'height' => $image['height'] ?? null, 'metadata' => $item];
            if ($layerize) {
                $z = $item['z_index'] ?? null;
                if (! is_int($z) || $z < 0 || $z > 16 || isset($seenLayers[$z])) {
                    throw new CreatorProviderException('invalid_layer', 'The model returned invalid layer ordering.');
                }
                $seenLayers[$z] = true;
                $bounds = $item['bounding_box'] ?? null;
                if ($bounds !== null) {
                    $this->validateBounds($bounds);
                }
                $output += ['z_index' => $z, 'bounding_box' => $bounds, 'name' => $item['name'] ?? null, 'description' => $item['description'] ?? null];
            }
            $normalized[] = $output;
        }
        if ($layerize) {
            if (! isset($seenLayers[0])) {
                throw new CreatorProviderException('invalid_layer', 'The model did not return a base layer.');
            }
            usort($normalized, fn (array $left, array $right): int => ($left['z_index'] ?? 0) <=> ($right['z_index'] ?? 0));
        }

        return $normalized;
    }

    private function validateBounds(mixed $bounds): void
    {
        if (! is_array($bounds)) {
            throw new CreatorProviderException('invalid_layer_bounds', 'The model returned invalid layer coordinates.');
        }
        foreach (['absolute' => 32768, 'normalized' => 1000] as $type => $maximum) {
            $coordinates = $bounds[$type] ?? null;
            if (! is_array($coordinates) || ! array_is_list($coordinates) || count($coordinates) !== 4) {
                throw new CreatorProviderException('invalid_layer_bounds', 'The model returned incomplete layer coordinates.');
            }
            foreach ($coordinates as $coordinate) {
                if (! is_int($coordinate) || $coordinate < 0 || $coordinate > $maximum) {
                    throw new CreatorProviderException('invalid_layer_bounds', 'The model returned out-of-range layer coordinates.');
                }
            }
            if ($coordinates[0] >= $coordinates[2] || $coordinates[1] >= $coordinates[3]) {
                throw new CreatorProviderException('invalid_layer_bounds', 'The model returned inverted layer coordinates.');
            }
        }
    }
}
