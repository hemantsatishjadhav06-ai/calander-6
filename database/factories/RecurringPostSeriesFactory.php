<?php

namespace Database\Factories;

use App\Models\RecurringPostSeries;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecurringPostSeries> */
class RecurringPostSeriesFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'source_post_id' => null, 'editorial_queue_id' => null, 'name' => fake()->words(3, true), 'timezone' => 'UTC', 'frequency' => 'weekly', 'interval' => 1, 'starts_on' => '2026-10-01', 'next_date' => '2026-10-01', 'local_time' => '09:00', 'ends_on' => null, 'max_occurrences' => null, 'lead_hours' => 168, 'generated_count' => 0, 'state' => 'active', 'revision' => 1, 'last_error' => null];
    }
}
