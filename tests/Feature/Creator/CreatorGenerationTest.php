<?php

use App\Models\CreatorGeneration;
use App\Models\CreatorProject;
use App\Models\Workspace;
use App\Services\Creator\CreatorAssetStorage;
use App\Services\Creator\CreatorOutputDownloader;
use App\Services\Creator\CreatorOutputNormalizer;
use App\Services\Creator\CreatorProviderException;
use App\Services\Creator\FalCreatorGateway;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    [$this->creatorUser, $this->creatorWorkspace] = ownerActingIn();
    config()->set('creator.fal.enabled', true);
    config()->set('creator.fal.key', 'test-only-not-a-real-key');
    config()->set('creator.fal.poll_interval_seconds', 0);
    config()->set('filesystems.default', 'local');
    Storage::fake('local');
    Http::preventStrayRequests();
});

/** @return array<string, mixed> */
function creatorGenerationPayload(array $overrides = []): array
{
    return array_replace(['expected_workspace_id' => test()->creatorWorkspace->id, 'operation' => 'generate', 'prompt' => 'A calm blue ocean', 'options' => ['num_images' => 1], 'idempotency_key' => (string) Str::uuid()], $overrides);
}

/** @return array<string, mixed> */
function creatorQueueReceipt(string $id = 'provider-request-123'): array
{
    $root = 'https://queue.fal.run/fal-ai/nano-banana-pro/requests/'.$id;

    return ['request_id' => $id, 'status' => 'IN_QUEUE', 'status_url' => $root.'/status', 'response_url' => $root, 'cancel_url' => $root.'/cancel', 'queue_position' => 2];
}

function fakeCreatorPricing(string $endpoint = 'fal-ai/nano-banana-pro', string $unit = 'image'): void
{
    Http::fake([
        'https://api.fal.ai/v1/models/pricing?*' => Http::response(['prices' => [['endpoint_id' => $endpoint, 'unit_price' => 0.15, 'unit' => $unit, 'currency' => 'USD']]]),
        'https://api.fal.ai/v1/models/pricing/estimate' => Http::response(['total_cost' => 0.15, 'currency' => 'USD']),
    ]);
}

it('reports disabled capabilities without contacting or exposing the provider', function (): void {
    config()->set('creator.fal.enabled', false);
    $this->getJson('/creator/capabilities')->assertOk()->assertJsonPath('configured', false)->assertJsonCount(10, 'operations')->assertDontSee('test-only-not-a-real-key');
    $this->postJson('/creator/generations/quote', creatorGenerationPayload())->assertStatus(503)->assertJsonPath('code', 'provider_unconfigured');
    Http::assertNothingSent();
});

it('requires an authenticated workspace member for generation', function (): void {
    auth()->logout();
    $this->getJson('/creator/capabilities')->assertUnauthorized();
    $this->postJson('/creator/generations/quote', creatorGenerationPayload())->assertUnauthorized();
    Http::assertNothingSent();
});

it('quotes from live pricing without sending prompts or source images to a model', function (): void {
    fakeCreatorPricing();
    $result = $this->postJson('/creator/generations/quote', creatorGenerationPayload())->assertOk()->assertJsonPath('generation.status', 'awaiting_confirmation')
        ->assertJsonPath('generation.quote.amount', 0.15)->assertJsonPath('generation.quote.hard_cap', false);
    expect($result->json('generation.endpoint_id'))->toBe('fal-ai/nano-banana-pro');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'queue.fal.run') || isset($request['prompt']) || isset($request['image_url']));
});

it('fails closed when current pricing is missing', function (): void {
    Http::fake(['https://api.fal.ai/v1/models/pricing?*' => Http::response(['prices' => []])]);
    $this->postJson('/creator/generations/quote', creatorGenerationPayload())->assertStatus(503)->assertJsonPath('code', 'quote_unavailable');
    expect(CreatorGeneration::count())->toBe(0);
    Http::assertSentCount(1);
});

it('uses provider historical estimates for compute priced operations', function (): void {
    $asset = app(CreatorAssetStorage::class)->storeBytes($this->creatorWorkspace->id, transparentPng(), 'image/png', 'Source', 'image', $this->creatorUser->id);
    fakeCreatorPricing('fal-ai/birefnet/v2', 'compute-second');
    $this->postJson('/creator/generations/quote', creatorGenerationPayload(['operation' => 'remove_background', 'asset_id' => $asset->id, 'options' => []]))->assertOk()->assertJsonPath('generation.quote.basis', 'historical_api_price');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/estimate') && $request['estimate_type'] === 'historical_api_price' && $request['endpoints']['fal-ai/birefnet/v2']['call_quantity'] === 1);
});

it('rejects arbitrary endpoints urls and unsupported options before any provider call', function (array $overrides): void {
    $this->postJson('/creator/generations/quote', creatorGenerationPayload($overrides))->assertUnprocessable();
    Http::assertNothingSent();
})->with([
    [['endpoint_id' => 'evil/model']], [['image_url' => 'http://169.254.169.254/']],
    [['operation' => 'unknown']], [['options' => ['num_images' => 5]]],
    [['options' => ['resolution' => '8K']]], [['options' => ['image_urls' => ['https://evil.test/image.png']]]],
]);

it('rejects source assets and projects from another workspace', function (): void {
    $foreign = Workspace::factory()->create();
    $asset = app(CreatorAssetStorage::class)->storeBytes($foreign->id, transparentPng(), 'image/png', 'Other', 'image', null);
    $this->postJson('/creator/generations/quote', creatorGenerationPayload(['operation' => 'edit', 'asset_id' => $asset->id]))->assertNotFound();
    $project = CreatorProject::factory()->create(['workspace_id' => $foreign->id]);
    $this->postJson('/creator/generations/quote', creatorGenerationPayload(['project_id' => $project->id]))->assertNotFound();
    Http::assertNothingSent();
});

it('deduplicates quotes and rejects idempotency key reuse with changed input', function (): void {
    fakeCreatorPricing();
    $input = creatorGenerationPayload();
    $id = $this->postJson('/creator/generations/quote', $input)->assertOk()->json('generation.id');
    $this->postJson('/creator/generations/quote', $input)->assertOk()->assertJsonPath('generation.id', $id);
    $this->postJson('/creator/generations/quote', array_replace($input, ['prompt' => 'A different landscape']))->assertConflict();
    expect(CreatorGeneration::count())->toBe(1);
    Http::assertSentCount(2);
});

it('submits only once after explicit confirmation including repeated clicks', function (): void {
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id]);
    Http::fake(['https://queue.fal.run/fal-ai/nano-banana-pro' => Http::response(creatorQueueReceipt())]);
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', [])->assertUnprocessable();
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'queued');
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'queued');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['limit_generations'] === true && $request['enable_web_search'] === false && $request->hasHeader('Authorization', 'Key test-only-not-a-real-key'));
});

it('never retries an uncertain submission timeout on repeated confirmation', function (): void {
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id]);
    Http::fake(['https://queue.fal.run/*' => Http::failedConnection()]);
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'unknown')->assertJsonPath('generation.error.code', 'submission_unknown');
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'unknown');
    expect($generation->refresh()->confirmed_at)->not->toBeNull();
});

it('retains an unknown receipt as unknown instead of following an unsafe queue url', function (): void {
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id]);
    Http::fake(['https://queue.fal.run/fal-ai/nano-banana-pro' => Http::response(array_replace(creatorQueueReceipt(), ['status_url' => 'https://evil.test/status']))]);
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'unknown');
    $this->getJson('/creator/generations/'.$generation->id)->assertOk()->assertJsonPath('generation.status', 'unknown');
    Http::assertSentCount(1);
});

it('will not submit an expired quote', function (): void {
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id, 'quote' => ['expires_at' => now()->subMinute()->toIso8601String()]]);
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertConflict();
    Http::assertNothingSent();
});

it('transmits immutable owned image bytes only at confirmation', function (): void {
    $bytes = transparentPng();
    $asset = app(CreatorAssetStorage::class)->storeBytes($this->creatorWorkspace->id, $bytes, 'image/png', 'Owned', 'image', $this->creatorUser->id);
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id, 'asset_id' => $asset->id, 'operation' => 'edit', 'endpoint_id' => 'fal-ai/nano-banana-pro/edit']);
    Http::fake(['https://queue.fal.run/fal-ai/nano-banana-pro/edit' => Http::response(creatorQueueReceipt())]);
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertOk();
    Http::assertSent(fn (Request $request): bool => $request['image_urls'] === ['data:image/png;base64,'.base64_encode($bytes)]);
});

it('imports a completed image once and preserves metadata without modifying a project', function (): void {
    $receipt = creatorQueueReceipt();
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id, 'status' => 'queued', 'provider_request_id' => $receipt['request_id'], 'status_url' => $receipt['status_url'], 'response_url' => $receipt['response_url'], 'cancel_url' => $receipt['cancel_url']]);
    Http::fake([
        $receipt['status_url'] => Http::response(['status' => 'COMPLETED', 'metrics' => ['inference_time' => 2.3]]),
        $receipt['response_url'] => Http::response(['images' => [['url' => 'https://v3.fal.media/files/image.png', 'width' => 4, 'height' => 4, 'extra' => 'preserved']], 'seed' => 123]),
        'https://v3.fal.media/files/image.png' => Http::response(transparentPng(), 200, ['Content-Type' => 'image/png']),
    ]);
    $first = $this->getJson('/creator/generations/'.$generation->id)->assertOk()->assertJsonPath('generation.status', 'completed')->assertJsonPath('generation.provider_metadata.result.seed', 123)->assertJsonPath('generation.outputs.0.metadata.extra', 'preserved');
    $this->getJson('/creator/generations/'.$generation->id)->assertOk()->assertJsonPath('generation.outputs.0.asset.id', $first->json('generation.outputs.0.asset.id'));
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'fal.media') && $request->hasHeader('Authorization'));
});

it('treats a completed status containing an error as failed', function (): void {
    expect(app(FalCreatorGateway::class)->state(['status' => 'COMPLETED', 'error_type' => 'content_policy_violation']))->toBe('failed');
});

it('preserves layer bounds stacking and all original metadata', function (): void {
    $bounds = ['absolute' => [10, 20, 100, 200], 'normalized' => [10, 20, 100, 200]];
    $result = app(CreatorOutputNormalizer::class)->normalize(['layers' => [
        ['z_index' => 1, 'image' => ['url' => 'https://v3.fal.media/layer.png'], 'bounding_box' => $bounds, 'name' => 'Subject', 'description' => 'Foreground subject', 'extra' => true],
        ['z_index' => 0, 'image' => ['url' => 'https://v3.fal.media/base.png']],
    ]], true);
    expect($result[0]['z_index'])->toBe(0)->and($result[1]['bounding_box'])->toBe($bounds)->and($result[1]['metadata']['extra'])->toBeTrue();
});

it('rejects malformed layer bounds', function (array $bounds): void {
    expect(fn () => app(CreatorOutputNormalizer::class)->normalize(['layers' => [
        ['z_index' => 0, 'image' => ['url' => 'https://v3.fal.media/base.png']],
        ['z_index' => 1, 'image' => ['url' => 'https://v3.fal.media/layer.png'], 'bounding_box' => $bounds],
    ]], true))->toThrow(CreatorProviderException::class);
})->with([
    [['absolute' => [0, 0, 20, 20], 'normalized' => [0, 0, 1001, 50]]],
    [['absolute' => [100, 0, 20, 20], 'normalized' => [0, 0, 50, 50]]],
    [['absolute' => [0, 0, 20], 'normalized' => [0, 0, 50, 50]]],
]);

it('blocks arbitrary output urls before download', function (string $url): void {
    expect(fn () => app(CreatorOutputDownloader::class)->download($url))->toThrow(CreatorProviderException::class);
    Http::assertNothingSent();
})->with(['http://v3.fal.media/image.png', 'https://fal.media.evil.test/image.png', 'https://127.0.0.1/image.png', 'https://storage.googleapis.com/other-bucket/image.png', 'https://user:password@v3.fal.media/image.png']);

it('does not interpret closing polling as cancellation and cancels only on explicit post', function (): void {
    $receipt = creatorQueueReceipt();
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id, 'status' => 'queued', 'provider_request_id' => $receipt['request_id'], 'status_url' => $receipt['status_url'], 'response_url' => $receipt['response_url'], 'cancel_url' => $receipt['cancel_url']]);
    Http::fake([$receipt['status_url'] => Http::response(['status' => 'IN_PROGRESS']), $receipt['cancel_url'] => Http::response(['status' => 'CANCELLATION_REQUESTED'], 202)]);
    $this->getJson('/creator/generations/'.$generation->id)->assertOk()->assertJsonPath('generation.status', 'running');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    $this->postJson('/creator/generations/'.$generation->id.'/cancel', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'cancel_requested');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
});

it('allows cancelling an unconfirmed quote without provider access', function (): void {
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id]);
    $this->postJson('/creator/generations/'.$generation->id.'/cancel', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'cancelled');
    Http::assertNothingSent();
});

it('scopes history and generation lookup to the current workspace', function (): void {
    $own = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id]);
    $foreign = CreatorGeneration::factory()->create();
    $this->getJson('/creator/generations')->assertOk()->assertJsonCount(1, 'generations')->assertJsonPath('generations.0.id', $own->id);
    $this->getJson('/creator/generations/'.$foreign->id)->assertNotFound();
    $this->postJson('/creator/generations/'.$foreign->id.'/confirm', ['confirm' => true])->assertNotFound();
    Http::assertNothingSent();
});

it('includes the verified 4K billing multiplier in unit price estimates', function (): void {
    config()->set('media.max_image_pixels', 20000000);
    fakeCreatorPricing();
    $this->postJson('/creator/generations/quote', creatorGenerationPayload(['options' => ['num_images' => 2, 'resolution' => '4K']]))->assertOk()->assertJsonPath('generation.quote.resolution_multiplier', 2)->assertJsonPath('generation.quote.quantity', 4);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/estimate') && $request['endpoints']['fal-ai/nano-banana-pro']['unit_quantity'] === 4);
});

it('quotes layerization for all possible outputs instead of a single image', function (): void {
    $asset = app(CreatorAssetStorage::class)->storeBytes($this->creatorWorkspace->id, transparentPng(512, 512), 'image/png', 'Source', 'image', $this->creatorUser->id);
    fakeCreatorPricing('bytedance/seedream/v5/pro/layerize', 'images');
    $this->postJson('/creator/generations/quote', creatorGenerationPayload(['operation' => 'layerize', 'asset_id' => $asset->id, 'options' => ['image_size' => 'auto']]))->assertOk()->assertJsonPath('generation.quote.image_count', 17)->assertJsonPath('generation.quote.quantity', 34);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/estimate') && $request['endpoints']['bytedance/seedream/v5/pro/layerize']['unit_quantity'] === 34);
});

it('keeps submit server errors unknown and does not replay them', function (): void {
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id]);
    Http::fake(['https://queue.fal.run/*' => Http::response(['detail' => 'Server error'], 503)]);
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'unknown');
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'unknown');
    Http::assertSentCount(1);
});

it('records a definite provider rejection without exposing response details', function (): void {
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id]);
    Http::fake(['https://queue.fal.run/*' => Http::response(['detail' => 'secret provider diagnostics'], 422)]);
    $this->postJson('/creator/generations/'.$generation->id.'/confirm', ['confirm' => true])->assertOk()->assertJsonPath('generation.status', 'failed')->assertDontSee('secret provider diagnostics');
    Http::assertSentCount(1);
});

it('rejects stale workspace context before pricing or generation', function (): void {
    $this->postJson('/creator/generations/quote', creatorGenerationPayload(['expected_workspace_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('expected_workspace_id');
    $payload = creatorGenerationPayload();
    unset($payload['expected_workspace_id']);
    $this->postJson('/creator/generations/quote', $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_workspace_id');
    Http::assertNothingSent();
});

it('retains completed provider output and retries a failed import without resubmission', function (): void {
    $receipt = creatorQueueReceipt();
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id, 'status' => 'queued', 'provider_request_id' => $receipt['request_id'], 'status_url' => $receipt['status_url'], 'response_url' => $receipt['response_url']]);
    Http::fake([
        $receipt['status_url'] => Http::response(['status' => 'COMPLETED']),
        $receipt['response_url'] => Http::response(['images' => [['url' => 'https://v3.fal.media/files/import.png']]]),
        'https://v3.fal.media/files/import.png' => Http::sequence()->push('unavailable', 503)->push(transparentPng(), 200, ['Content-Type' => 'image/png']),
    ]);
    $this->getJson('/creator/generations/'.$generation->id)->assertOk()->assertJsonPath('generation.status', 'import_failed')->assertJsonPath('generation.can_retry_import', true);
    $this->getJson('/creator/generations/'.$generation->id)->assertOk()->assertJsonPath('generation.status', 'completed');
    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('rejects 4K before pricing when its output can exceed the configured image import limit', function (): void {
    config()->set('media.max_image_pixels', 16000000);
    $this->getJson('/creator/capabilities')->assertOk()->assertJsonPath('resolutions', ['1K', '2K']);
    $this->postJson('/creator/generations/quote', creatorGenerationPayload(['options' => ['resolution' => '4K']]))->assertUnprocessable()->assertJsonValidationErrors('options.resolution');
    Http::assertNothingSent();
});

it('imports bounded batches and resumes without refetching or repeating generation', function (): void {
    $receipt = creatorQueueReceipt();
    $generation = CreatorGeneration::factory()->create(['workspace_id' => $this->creatorWorkspace->id, 'status' => 'queued', 'provider_request_id' => $receipt['request_id'], 'status_url' => $receipt['status_url'], 'response_url' => $receipt['response_url']]);
    Http::fake([
        $receipt['status_url'] => Http::response(['status' => 'COMPLETED']),
        $receipt['response_url'] => Http::response(['images' => [['url' => 'https://v3.fal.media/files/one.png'], ['url' => 'https://v3.fal.media/files/two.png']]]),
        'https://v3.fal.media/files/*' => fn (): PromiseInterface => Http::response(transparentPng(), 200, ['Content-Type' => 'image/png']),
    ]);
    $this->getJson('/creator/generations/'.$generation->id)->assertOk()->assertJsonPath('generation.status', 'importing')->assertJsonCount(1, 'generation.outputs')->assertJsonPath('generation.total_outputs', 2);
    $this->getJson('/creator/generations/'.$generation->id)->assertOk()->assertJsonPath('generation.status', 'completed')->assertJsonCount(2, 'generation.outputs');
    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});
