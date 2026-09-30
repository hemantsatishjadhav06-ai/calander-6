<?php

declare(strict_types=1);

namespace App\Services\Creator;

use App\Models\CreatorAsset;
use App\Models\CreatorGeneration;
use App\Models\CreatorProject;
use App\Support\FileStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreatorGenerationService
{
    public function __construct(
        private readonly CreatorModelRegistry $models,
        private readonly FalCreatorGateway $gateway,
        private readonly CreatorOutputNormalizer $normalizer,
        private readonly CreatorOutputDownloader $downloader,
        private readonly CreatorAssetStorage $assets,
    ) {}

    /**
     * @param array<string, mixed> $payload */
    public function quote(string $workspaceId, string $actorId, array $payload): CreatorGeneration
    {
        if (($payload['expected_workspace_id'] ?? null) !== $workspaceId) {
            throw ValidationException::withMessages(['expected_workspace_id' => 'Your active workspace changed. Reopen Creator before requesting a quote.']);
        }
        $this->gateway->assertConfigured();
        $input = $this->models->validate($payload);
        $projectId = $payload['project_id'] ?? null;
        if ($projectId !== null) {
            CreatorProject::query()->where('workspace_id', $workspaceId)->whereKey($projectId)->firstOrFail();
        }
        if ($input['asset_id'] !== null) {
            $asset = $this->ownedAsset($workspaceId, $input['asset_id']);
            $this->validateAssetForOperation($asset, $input);
        }
        $hash = hash('sha256', json_encode([$input, $projectId], JSON_THROW_ON_ERROR));
        $existing = CreatorGeneration::query()->where('workspace_id', $workspaceId)->where('idempotency_key', $payload['idempotency_key'])->first();
        if ($existing !== null) {
            abort_unless(hash_equals($existing->input_hash, $hash), 409, 'This idempotency key belongs to a different request.');

            return $existing;
        }
        $quote = $this->gateway->quote($input);
        $generation = CreatorGeneration::query()->firstOrCreate(
            ['workspace_id' => $workspaceId, 'idempotency_key' => $payload['idempotency_key']],
            [...$input, 'project_id' => $projectId, 'created_by_id' => $actorId, 'input_hash' => $hash, 'status' => 'awaiting_confirmation', 'quote' => $quote, 'outputs' => [], 'provider_metadata' => []],
        );
        abort_unless(hash_equals($generation->input_hash, $hash), 409, 'This idempotency key belongs to a different request.');

        return $generation;
    }

    public function confirm(CreatorGeneration $generation): CreatorGeneration
    {
        $this->gateway->assertConfigured();
        $claim = DB::transaction(function () use ($generation): ?array {
            $locked = $this->locked($generation);
            if ($locked->status !== 'awaiting_confirmation') {
                return null;
            }
            abort_if(now()->gte($locked->quote['expires_at'] ?? now()), 409, 'This estimate expired. Request a new quote before confirming.');
            $validatedInput = $this->models->validate(['operation' => $locked->operation, 'prompt' => $locked->prompt, 'asset_id' => $locked->asset_id, 'options' => $locked->options]);
            $dataUri = null;
            if ($locked->asset_id !== null) {
                $asset = $this->ownedAsset($locked->workspace_id, $locked->asset_id);
                $this->validateAssetForOperation($asset, $validatedInput);
                $bytes = FileStorage::disk($asset->disk)->get($asset->path);
                if (! is_string($bytes) || strlen($bytes) > 8388608 || ! hash_equals($asset->sha256, hash('sha256', $bytes))) {
                    throw ValidationException::withMessages(['asset_id' => 'The source image is missing or has changed. Upload it again.']);
                }
                CreatorAssetStorage::inspect($bytes, $asset->mime);
                $dataUri = 'data:'.$asset->mime.';base64,'.base64_encode($bytes);
            }
            $input = $this->models->providerInput($validatedInput, $dataUri);
            $locked->forceFill(['status' => 'submitting', 'confirmed_at' => now(), 'error' => null])->save();

            return $input;
        });
        if ($claim === null) {
            return $generation->refresh();
        }
        try {
            $receipt = $this->gateway->submit($generation->endpoint_id, $claim);
            $generation->forceFill([
                'provider_request_id' => $receipt['request_id'], 'status_url' => $receipt['status_url'],
                'response_url' => $receipt['response_url'], 'cancel_url' => $receipt['cancel_url'],
                'status' => 'queued', 'queue_position' => is_int($receipt['queue_position'] ?? null) ? $receipt['queue_position'] : null,
                'provider_metadata' => ['receipt' => $receipt], 'error' => null,
            ])->save();
        } catch (CreatorProviderException $error) {
            $generation->forceFill(['status' => $error->outcomeUnknown ? 'unknown' : 'failed', 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]])->save();
        }

        return $generation->refresh();
    }

    public function poll(CreatorGeneration $generation): CreatorGeneration
    {
        return DB::transaction(function () use ($generation): CreatorGeneration {
            $generation = $this->locked($generation);
            if ($generation->status === 'submitting' && $generation->confirmed_at?->lt(now()->subSeconds(90))) {
                $generation->forceFill(['status' => 'unknown', 'error' => ['code' => 'submission_unknown', 'message' => 'Submission was interrupted. It may be running or billed; check provider request history before generating again.']])->save();
            }
            if (! in_array($generation->status, ['queued', 'running', 'cancel_requested', 'unknown', 'import_failed', 'importing'], true) || $generation->provider_request_id === null || $generation->status_url === null) {
                return $generation;
            }
            if ($generation->last_polled_at?->gt(now()->subSeconds((int) config('creator.fal.poll_interval_seconds', 3)))) {
                return $generation;
            }
            $generation->forceFill(['last_polled_at' => now()])->save();
            try {
                $metadata = $generation->provider_metadata ?? [];
                $result = $metadata['result'] ?? null;
                if (! is_array($result)) {
                    $status = $this->gateway->status($generation->status_url, $generation->provider_request_id);
                    $state = $this->gateway->state($status);
                    $metadata['last_status'] = $status;
                    $generation->forceFill(['provider_metadata' => $metadata, 'queue_position' => is_int($status['queue_position'] ?? null) ? $status['queue_position'] : null])->save();
                    if ($state !== 'completed') {
                        $generation->forceFill([
                            'status' => $generation->status === 'cancel_requested' && in_array($state, ['queued', 'running'], true) ? 'cancel_requested' : $state,
                            'error' => $state === 'failed' ? ['code' => 'generation_failed', 'message' => 'The provider reported that generation failed. No new request was submitted.'] : null,
                        ])->save();

                        return $generation;
                    }
                    $result = $this->gateway->result((string) $generation->response_url, $generation->provider_request_id);
                    $metadata['result'] = $result;
                    $generation->forceFill(['provider_metadata' => $metadata])->save();
                }
                $outputs = $this->normalizer->normalize($result, $generation->operation === 'layerize');
                $metadata['output_count'] = count($outputs);
                $generation->forceFill(['status' => 'importing', 'provider_metadata' => $metadata])->save();
                $imported = $generation->outputs ?? [];
                foreach ($outputs as $index => $output) {
                    if (isset($imported[$index])) {
                        continue;
                    }
                    $image = $this->downloader->download($output['url']);
                    $asset = $this->assets->storeBytes($generation->workspace_id, $image['bytes'], $image['mime'], mb_substr((string) ($output['name'] ?? 'Generated '.$generation->operation.' '.($index + 1)), 0, 200), 'image', $generation->created_by_id);
                    $imported[] = [...$output, 'asset' => $asset->toView(), 'width' => $asset->width, 'height' => $asset->height];
                    $generation->forceFill(['outputs' => $imported])->save();
                    break;
                }
                $generation->forceFill(['status' => count($imported) === count($outputs) ? 'completed' : 'importing', 'error' => null])->save();
            } catch (CreatorProviderException $error) {
                $generation->forceFill([
                    'status' => is_array(($generation->provider_metadata ?? [])['result'] ?? null) ? 'import_failed' : $generation->status,
                    'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()],
                ])->save();
            } catch (ValidationException) {
                $generation->forceFill(['status' => 'import_failed', 'error' => ['code' => 'output_import_failed', 'message' => 'The provider result could not be imported within the application image limits. The provider result is retained; this request will not be resubmitted.']])->save();
            }

            return $generation;
        });
    }

    public function cancel(CreatorGeneration $generation): CreatorGeneration
    {
        return DB::transaction(function () use ($generation): CreatorGeneration {
            $generation = $this->locked($generation);
            if ($generation->status === 'awaiting_confirmation') {
                $generation->forceFill(['status' => 'cancelled', 'error' => null])->save();

                return $generation;
            }
            if (! in_array($generation->status, ['queued', 'running'], true) || $generation->cancel_url === null || $generation->provider_request_id === null) {
                return $generation;
            }
            try {
                $result = $this->gateway->cancel($generation->cancel_url, $generation->provider_request_id);
                $metadata = $generation->provider_metadata ?? [];
                $metadata['cancellation'] = $result;
                $generation->forceFill(['status' => $this->gateway->state($result) === 'cancelled' ? 'cancelled' : 'cancel_requested', 'provider_metadata' => $metadata, 'error' => null])->save();
            } catch (CreatorProviderException $error) {
                $generation->forceFill(['error' => ['code' => $error->errorCode, 'message' => 'Cancellation was not confirmed. The job may still run and incur charges.']])->save();
            }

            return $generation;
        });
    }

    private function locked(CreatorGeneration $generation): CreatorGeneration
    {
        return CreatorGeneration::query()->where('workspace_id', $generation->workspace_id)->whereKey($generation->id)->lockForUpdate()->firstOrFail();
    }

    private function ownedAsset(string $workspaceId, string $assetId): CreatorAsset
    {
        return CreatorAsset::query()->where('workspace_id', $workspaceId)->whereKey($assetId)->firstOrFail();
    }

    /**
     * @param array<string, mixed> $input */
    private function validateAssetForOperation(CreatorAsset $asset, array $input): void
    {
        if ($input['operation'] === 'layerize' && ($asset->width * $asset->height < 512 * 512 || $asset->width / max(1, $asset->height) > 16 || $asset->height / max(1, $asset->width) > 16)) {
            throw ValidationException::withMessages(['asset_id' => 'Layer separation requires at least 512 × 512 pixels in total and an aspect ratio between 1:16 and 16:1.']);
        }
        if ($input['operation'] === 'upscale' && $asset->width * $asset->height * ((float) $input['options']['scale'] ** 2) > (int) config('media.max_image_pixels', 16000000)) {
            throw ValidationException::withMessages(['options.scale' => 'This upscale would exceed the application image pixel limit. Choose a smaller scale.']);
        }
    }
}
