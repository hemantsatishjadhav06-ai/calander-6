<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContentIdea;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentIdea> */
class ContentIdeaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'title' => fake()->sentence(3), 'tags' => [], 'status' => 'inbox', 'revision' => 1];
    }
}
