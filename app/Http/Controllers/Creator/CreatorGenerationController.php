<?php

declare(strict_types=1);

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\ConfirmCreatorGenerationRequest;
use App\Http\Requests\Creator\QuoteCreatorGenerationRequest;
use App\Models\CreatorGeneration;
use App\Models\Post;
use App\Services\Creator\CreatorGenerationService;
use App\Services\Creator\CreatorModelRegistry;
use App\Services\Creator\FalCreatorGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CreatorGenerationController extends Controller
{
    public function __construct(private readonly CreatorGenerationService $generations) {}

    public function capabilities(Request $request, CreatorModelRegistry $models, FalCreatorGateway $gateway): JsonResponse
    {
        Gate::authorize('create', Post::class);

        return response()->json(['workspace_id' => $request->user()->current_workspace_id, 'configured' => $gateway->configured(), 'provider' => 'fal', 'currency' => 'USD', 'resolutions' => (int) config('media.max_image_pixels', 16000000) >= 20000000 ? ['1K', '2K', '4K'] : ['1K', '2K'], 'operations' => $models->operations(), 'reason' => $gateway->configured() ? null : 'AI generation is disabled until a server administrator enables Creator and configures FAL_KEY.']);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('create', Post::class);
        $request->validate(['project_id' => ['nullable', 'uuid']]);
        $query = CreatorGeneration::query()->where('workspace_id', $request->user()->current_workspace_id);
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->string('project_id')->toString());
        }

        return response()->json(['workspace_id' => $request->user()->current_workspace_id, 'generations' => $query->latest()->limit(20)->get()->map->toView()]);
    }

    public function quote(QuoteCreatorGenerationRequest $request): JsonResponse
    {
        return response()->json(['generation' => $this->generations->quote((string) $request->user()->current_workspace_id, (string) $request->user()->id, $request->validated())->toView()]);
    }

    public function confirm(ConfirmCreatorGenerationRequest $request, CreatorGeneration $creatorGeneration): JsonResponse
    {
        return response()->json(['generation' => $this->generations->confirm($creatorGeneration)->toView()]);
    }

    public function show(CreatorGeneration $creatorGeneration): JsonResponse
    {
        Gate::authorize('create', Post::class);

        return response()->json(['generation' => $this->generations->poll($creatorGeneration)->toView()]);
    }

    public function cancel(ConfirmCreatorGenerationRequest $request, CreatorGeneration $creatorGeneration): JsonResponse
    {
        return response()->json(['generation' => $this->generations->cancel($creatorGeneration)->toView()]);
    }
}
