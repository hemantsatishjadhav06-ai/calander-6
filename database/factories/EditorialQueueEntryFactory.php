<?php

namespace Database\Factories;

use App\Models\EditorialQueue;
use App\Models\EditorialQueueEntry;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EditorialQueueEntry> */
class EditorialQueueEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['workspace_id' => Workspace::factory(), 'editorial_queue_id' => EditorialQueue::factory(), 'post_id' => Post::factory(), 'priority' => 50, 'status' => 'waiting', 'blocked_reason' => null, 'scheduled_at' => null];
    }
}
