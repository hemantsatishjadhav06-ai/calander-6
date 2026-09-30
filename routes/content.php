<?php

declare(strict_types=1);

use App\Http\Controllers\Content\ContentWorkspaceController;
use App\Models\ContentIdea;
use App\Models\ContentTemplate;
use Illuminate\Support\Facades\Route;

Route::bind('contentTemplate', fn (string $value): ContentTemplate => ContentTemplate::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)->whereKey($value)->firstOrFail());
Route::bind('contentIdea', fn (string $value): ContentIdea => ContentIdea::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)->whereKey($value)->firstOrFail());

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('brand', [ContentWorkspaceController::class, 'index'])->name('content.brand.index');
    Route::get('templates', [ContentWorkspaceController::class, 'index'])->name('content.templates.index');
    Route::get('ideas', [ContentWorkspaceController::class, 'index'])->name('content.ideas.index');
    Route::get('content/context', [ContentWorkspaceController::class, 'context'])->name('content.context');
    Route::middleware('throttle:60,1')->group(function (): void {
        Route::put('brand', [ContentWorkspaceController::class, 'updateBrand'])->name('content.brand.update');
        Route::post('templates', [ContentWorkspaceController::class, 'storeTemplate'])->name('content.templates.store');
        Route::put('templates/{contentTemplate}', [ContentWorkspaceController::class, 'updateTemplate'])->name('content.templates.update');
        Route::post('templates/{contentTemplate}/instantiate', [ContentWorkspaceController::class, 'instantiateTemplate'])->name('content.templates.instantiate');
        Route::post('ideas', [ContentWorkspaceController::class, 'storeIdea'])->name('content.ideas.store');
        Route::put('ideas/{contentIdea}', [ContentWorkspaceController::class, 'updateIdea'])->name('content.ideas.update');
        Route::post('ideas/{contentIdea}/draft', [ContentWorkspaceController::class, 'convertIdea'])->name('content.ideas.convert');
        Route::post('content/prompt', [ContentWorkspaceController::class, 'prompt'])->name('content.prompt');
    });
});
