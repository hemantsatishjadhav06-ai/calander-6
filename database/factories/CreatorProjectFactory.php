<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreatorProject;
use App\Models\Workspace;
use App\Services\Creator\CreatorDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreatorProject> */
class CreatorProjectFactory extends Factory
{
    protected $model = CreatorProject::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $document = ['schema_version' => 1, 'canvas' => ['width' => 1080, 'height' => 1080],
            'slides' => [['id' => 'slide-1', 'name' => 'Slide 1', 'background_color' => '#ffffff', 'layers' => []]]];

        return ['workspace_id' => Workspace::factory(), 'name' => 'Untitled design',
            'revision' => 1, 'document' => $document, 'document_hash' => fn (array $attributes): string => CreatorDocument::hash($attributes['document'])];
    }
}
