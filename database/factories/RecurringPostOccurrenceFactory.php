<?php

namespace Database\Factories;

use App\Models\RecurringPostOccurrence;
use App\Models\RecurringPostSeries;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecurringPostOccurrence> */
class RecurringPostOccurrenceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'recurring_post_series_id' => RecurringPostSeries::factory(), 'occurrence_key' => '2026-10-01', 'intended_at' => '2026-10-01 09:00:00', 'post_id' => null, 'status' => 'generated'];
    }
}
