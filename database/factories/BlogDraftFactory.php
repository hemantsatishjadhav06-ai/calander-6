<?php

namespace Database\Factories;

use App\Models\BlogDraft;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Override;

/**
 * @extends Factory<BlogDraft>
 */
class BlogDraftFactory extends Factory
{
    #[Override]
    protected $model = BlogDraft::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'author_id' => User::factory(),
            'title' => $title = fake()->sentence(5),
            'slug' => Str::slug($title),
            'body' => fake()->paragraphs(3, true),
            'excerpt' => fake()->sentence(),
        ];
    }
}
