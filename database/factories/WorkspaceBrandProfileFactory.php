<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceBrandProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkspaceBrandProfile> */
class WorkspaceBrandProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'revision' => 1, 'palette' => ['#2563eb'], 'default_hashtags' => [], 'first_comment_enabled' => false];
    }
}
