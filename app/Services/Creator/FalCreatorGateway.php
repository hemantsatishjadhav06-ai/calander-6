<?php

declare(strict_types=1);

namespace App\Services\Creator;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Queue transport adapted from Aquora lib/gateway/fal.js and generation.js.
 * Copyright (c) 2026 Open Generative AI Contributors. MIT license included in
 * licenses/open-generative-ai-MIT.txt. No Aquora-origin requests or client keys.
 */
final class FalCreatorGateway
{
    public function __construct(private readonly CreatorModelRegistry $models) {}

    public function configured(): bool
    {
        $key = config('creator.fal.key');

        return config('creator.fal.enabled') === true && is_string($key) && trim($key) !== '';
    }

    public function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new CreatorProviderException('provider_unconfigured', 'AI generation is disabled. A server administrator must enable Creator and configure FAL_KEY.', 503);
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function quote(array $input): array
    {
        $this->assertConfigured();
        $endpoint = $input['endpoint_id'];
        $this->models->assertEndpoint($endpoint);
        $prices = $this->request('GET', 'https://api.fal.ai/v1/models/pricing', ['endpoint_id' => $endpoint]);
        $price = collect($prices['prices'] ?? [])->first(fn (mixed $price): bool => is_array($price) && ($price['endpoint_id'] ?? null) === $endpoint);
        if (! is_array($price) || ! is_numeric($price['unit_price'] ?? null) || ! is_finite((float) $price['unit_price']) || (float) $price['unit_price'] < 0 || ($price['currency'] ?? null) !== 'USD') {
            throw new CreatorProviderException('quote_unavailable', 'A current USD price is unavailable. Generation has not been submitted.', 503);
        }
        $unit = (string) ($price['unit'] ?? '');
        $perImage = in_array(strtolower($unit), ['image', 'images'], true);
        $basis = $perImage ? 'unit_price' : 'historical_api_price';
        $imageCount = (int) ($input['options']['num_images'] ?? 1);
        // Published Fal Nano Banana Pro billing: 4K consumes twice the base image rate.
        $resolutionMultiplier = str_starts_with($endpoint, 'fal-ai/nano-banana-pro') && ($input['options']['resolution'] ?? '1K') === '4K' ? 2 : 1;
        $assumption = 'Estimate uses the requested output count and resolution.';
        if ($input['operation'] === 'layerize') {
            $imageCount = 17;
            $resolutionMultiplier = ($input['options']['image_size'] ?? 'auto') === 'auto_1K' ? 1 : 2;
            $assumption = 'Layer estimate assumes all 17 possible generated layers. Unless 1K is selected, it conservatively uses the higher pixel-area rate (twice the base rate). Actual layer count and output size determine billing.';
        }
        $quantity = $imageCount * $resolutionMultiplier;
        $estimate = $this->request('POST', 'https://api.fal.ai/v1/models/pricing/estimate', [
            'estimate_type' => $basis,
            'endpoints' => [$endpoint => $perImage ? ['unit_quantity' => $quantity] : ['call_quantity' => 1]],
        ]);
        if (! is_numeric($estimate['total_cost'] ?? null) || ! is_finite((float) $estimate['total_cost']) || (float) $estimate['total_cost'] < 0 || ($estimate['currency'] ?? null) !== 'USD') {
            throw new CreatorProviderException('quote_unavailable', 'A current cost estimate is unavailable. Generation has not been submitted.', 503);
        }

        return [
            'amount' => (float) $estimate['total_cost'], 'currency' => 'USD', 'basis' => $basis,
            'unit_price' => (float) $price['unit_price'], 'unit' => $unit,
            'quantity' => $perImage ? $quantity : 1, 'image_count' => $imageCount, 'resolution_multiplier' => $resolutionMultiplier, 'estimated' => true, 'hard_cap' => false,
            'expires_at' => now()->addSeconds((int) config('creator.fal.quote_ttl_seconds', 300))->toIso8601String(),
            'assumption' => $assumption,
            'disclosure' => $assumption.' One-time generation charged to the configured fal.ai account. This is an estimate, not a spending cap. Resolution, output count and actual compute usage may change the final charge. Cancellation may not stop work or refund charges. Confirming sends this prompt and the selected image to fal.ai.',
            'provider_metadata' => ['price' => $price, 'estimate' => $estimate],
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function submit(string $endpoint, array $input): array
    {
        $this->models->assertEndpoint($endpoint);
        $body = $this->request('POST', 'https://queue.fal.run/'.$endpoint, $input, true);
        $requestId = $body['request_id'] ?? null;
        if (! is_string($requestId) || ! preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $requestId)) {
            throw new CreatorProviderException('submission_unknown', 'The provider may have accepted the request but returned an invalid receipt. Do not submit it again automatically.', 502, true);
        }
        try {
            foreach (['response_url' => '', 'status_url' => '/status', 'cancel_url' => '/cancel'] as $key => $suffix) {
                $this->validateQueueUrl((string) ($body[$key] ?? ''), $requestId, $suffix);
            }
        } catch (CreatorProviderException) {
            throw new CreatorProviderException('submission_unknown', 'The provider returned an untrusted receipt. The request may still be billed; it will not be resubmitted.', 502, true);
        }

        return $body;
    }

    /** @return array<string, mixed> */
    public function status(string $url, string $requestId): array
    {
        $this->validateQueueUrl($url, $requestId, '/status');

        return $this->request('GET', $url);
    }

    /** @return array<string, mixed> */
    public function result(string $url, string $requestId): array
    {
        $this->validateQueueUrl($url, $requestId, '');

        return $this->request('GET', $url);
    }

    /** @return array<string, mixed> */
    public function cancel(string $url, string $requestId): array
    {
        $this->validateQueueUrl($url, $requestId, '/cancel');

        return $this->request('PUT', $url);
    }

    public function validateQueueUrl(string $url, string $requestId, string $suffix): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'queue.fal.run'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || ! preg_match('#^/(?:[A-Za-z0-9_-]+/){2,6}requests/'.preg_quote($requestId, '#').preg_quote($suffix, '#').'$#', $parts['path'] ?? '')) {
            throw new CreatorProviderException('invalid_provider_url', 'The provider returned an unexpected queue URL.');
        }
    }

    /** @param array<string, mixed> $body */
    public function state(array $body): string
    {
        if (! empty($body['error']) || ! empty($body['error_type'])) {
            return 'failed';
        }

        return match (strtoupper((string) ($body['status'] ?? ''))) {
            'IN_QUEUE' => 'queued', 'IN_PROGRESS' => 'running', 'COMPLETED' => 'completed',
            'FAILED', 'ERROR' => 'failed', 'CANCELLED', 'CANCELED' => 'cancelled', default => 'unknown',
        };
    }

    private function client(): PendingRequest
    {
        $this->assertConfigured();

        return Http::withHeaders(['Authorization' => 'Key '.config('creator.fal.key')])->acceptJson()
            ->connectTimeout(5)->timeout((int) config('creator.fal.http_timeout_seconds', 30))
            ->withOptions(['allow_redirects' => false]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $data = [], bool $submission = false): array
    {
        try {
            $response = $this->client()->send($method, $url, [$method === 'GET' ? 'query' : 'json' => $data]);
        } catch (ConnectionException) {
            throw new CreatorProviderException($submission ? 'submission_unknown' : 'provider_unreachable', $submission
                ? 'Submission could not be confirmed. It may be running or billed. Do not submit again; check the provider request history.'
                : 'The provider could not be reached. The existing request has not been resubmitted.', 503, $submission);
        }
        if (! $response->successful()) {
            $this->failResponse($response, $submission);
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new CreatorProviderException($submission ? 'submission_unknown' : 'invalid_provider_response', 'The provider returned an unreadable response.', 502, $submission);
        }

        return $body;
    }

    private function failResponse(Response $response, bool $submission): never
    {
        $status = $response->status();
        $unknown = $submission && ($status >= 500 || in_array($status, [408, 409], true) || $status < 400);
        $message = match (true) {
            $unknown => 'Submission outcome is unknown. The request may be billed and will not be resubmitted automatically.',
            in_array($status, [401, 403], true) => 'The server Fal credentials are not authorized. Ask an administrator to check the configuration.',
            $status === 429 => 'Fal is rate limiting requests. No automatic submission retry was made.',
            $status === 404 => 'The provider request or model was not found.',
            $status >= 500 => 'Fal is temporarily unavailable. Check this same request again later.',
            default => 'Fal rejected this request. Check the model settings and image requirements.',
        };
        throw new CreatorProviderException($unknown ? 'submission_unknown' : 'provider_error', $message, $status === 429 ? 429 : 502, $unknown);
    }
}
