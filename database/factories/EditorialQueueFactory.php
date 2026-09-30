<?php

namespace Database\Factories;

use App\Models\EditorialQueue;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EditorialQueue> */
class EditorialQueueFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'name' => fake()->unique()->words(3, true), 'category' => 'General', 'priority' => 50, 'state' => 'active', 'revision' => 1];
    }
}
