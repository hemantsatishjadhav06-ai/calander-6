<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreatorAsset;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CreatorAsset> */
class CreatorAssetFactory extends Factory
{
    protected $model = CreatorAsset::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'name' => 'Image', 'kind' => 'image',
            'disk' => 'local', 'path' => 'creator/assets/'.Str::uuid().'.png', 'mime' => 'image/png',
            'size_bytes' => 100, 'width' => 100, 'height' => 100, 'sha256' => hash('sha256', 'fixture')];
    }
}
