<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasWorkspaceScope;
use Carbon\CarbonInterface;
use Database\Factories\CreatorGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property ?string $created_by_id
 * @property ?string $project_id
 * @property string $idempotency_key
 * @property string $input_hash
 * @property string $operation
 * @property string $endpoint_id
 * @property string $prompt
 * @property ?string $asset_id
 * @property array<string, mixed> $options
 * @property string $status
 * @property array<string, mixed> $quote
 * @property ?string $provider_request_id
 * @property ?string $status_url
 * @property ?string $response_url
 * @property ?string $cancel_url
 * @property array<string, mixed> $provider_metadata
 * @property list<array<string, mixed>> $outputs
 * @property ?array{code:string,message:string} $error
 * @property ?int $queue_position
 * @property ?CarbonInterface $confirmed_at
 * @property ?CarbonInterface $last_polled_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
#[Fillable(['workspace_id', 'created_by_id', 'project_id', 'idempotency_key', 'input_hash', 'operation', 'endpoint_id', 'prompt', 'asset_id', 'options', 'status', 'quote', 'provider_request_id', 'status_url', 'response_url', 'cancel_url', 'provider_metadata', 'outputs', 'error', 'queue_position', 'confirmed_at', 'last_polled_at'])]
class CreatorGeneration extends Model
{
    /** @use HasFactory<CreatorGenerationFactory> */
    use HasFactory, HasUuids, HasWorkspaceScope;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['options' => 'array', 'quote' => 'array', 'provider_metadata' => 'array', 'outputs' => 'array', 'error' => 'array', 'queue_position' => 'integer', 'confirmed_at' => 'immutable_datetime', 'last_polled_at' => 'immutable_datetime'];
    }

    /** @return array<string, mixed> */
    public function toView(): array
    {
        return [
            'id' => $this->id, 'project_id' => $this->project_id, 'operation' => $this->operation,
            'endpoint_id' => $this->endpoint_id, 'prompt' => $this->prompt, 'asset_id' => $this->asset_id,
            'options' => $this->options, 'status' => $this->status, 'quote' => $this->quote,
            'outputs' => $this->outputs ?? [], 'provider_request_id' => $this->provider_request_id,
            'provider_metadata' => $this->provider_metadata ?? [], 'error' => $this->error,
            'queue_position' => $this->queue_position, 'total_outputs' => $this->provider_metadata['output_count'] ?? null,
            'created_at' => $this->created_at->toIso8601String(), 'updated_at' => $this->updated_at->toIso8601String(),
            'can_confirm' => $this->status === 'awaiting_confirmation' && now()->lt($this->quote['expires_at'] ?? now()),
            'can_retry_import' => $this->status === 'import_failed',
            'can_cancel' => in_array($this->status, ['awaiting_confirmation', 'queued', 'running'], true),
        ];
    }
}
