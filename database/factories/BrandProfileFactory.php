<?php

namespace Database\Factories;

use App\Models\BrandProfile;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandProfile>
 */
class BrandProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'website_url' => 'https://example.com',
            'instagram_username' => 'example_brand',
            'facebook_page_id' => '123456789',
            'facebook_page_url' => 'https://www.facebook.com/123456789',
            'x_username' => 'example_brand',
        ];
    }
}
