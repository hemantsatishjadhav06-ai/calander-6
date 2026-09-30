<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreatorGeneration;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CreatorGeneration> */
class CreatorGenerationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(), 'idempotency_key' => (string) Str::uuid(),
            'input_hash' => hash('sha256', 'fixture'), 'operation' => 'generate',
            'endpoint_id' => 'fal-ai/nano-banana-pro', 'prompt' => 'A calm blue sea',
            'options' => ['aspect_ratio' => '1:1', 'resolution' => '1K', 'num_images' => 1, 'output_format' => 'png'],
            'status' => 'awaiting_confirmation',
            'quote' => ['amount' => 0.15, 'currency' => 'USD', 'estimated' => true, 'hard_cap' => false, 'expires_at' => now()->addMinutes(5)->toIso8601String()],
            'outputs' => [], 'provider_metadata' => [],
        ];
    }
}
