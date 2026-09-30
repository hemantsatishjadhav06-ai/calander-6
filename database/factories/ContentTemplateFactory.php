<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContentTemplate;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentTemplate> */
class ContentTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'name' => fake()->sentence(3), 'brief' => fake()->sentence(), 'hashtags' => [], 'revision' => 1];
    }
}
