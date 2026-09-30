<?php

declare(strict_types=1);

use App\Http\Controllers\Creator\CreatorAssetController;
use App\Http\Controllers\Creator\CreatorExportController;
use App\Http\Controllers\Creator\CreatorProjectController;
use App\Models\CreatorAsset;
use App\Models\CreatorProject;
use Illuminate\Support\Facades\Route;

Route::bind('creatorProject', fn (string $value): CreatorProject => CreatorProject::withoutGlobalScopes()
    ->where('workspace_id', request()->user()?->current_workspace_id)->whereKey($value)->firstOrFail());
Route::bind('creatorAsset', fn (string $value): CreatorAsset => CreatorAsset::withoutGlobalScopes()
    ->where('workspace_id', request()->user()?->current_workspace_id)->whereKey($value)->firstOrFail());

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('creator/projects', [CreatorProjectController::class, 'index'])->name('creator.projects.index');
    Route::get('creator/projects/{creatorProject}', [CreatorProjectController::class, 'show'])->name('creator.projects.show');
    Route::get('creator/assets', [CreatorAssetController::class, 'index'])->name('creator.assets.index');
    Route::get('creator/assets/{creatorAsset}/content', [CreatorAssetController::class, 'content'])->name('creator.assets.content');
    Route::get('posts/{post}/creator-context', [CreatorExportController::class, 'context'])->name('posts.creator-context.show');
    Route::middleware('throttle:60,1')->group(function (): void {
        Route::post('creator/projects', [CreatorProjectController::class, 'store'])->name('creator.projects.store');
        Route::put('creator/projects/{creatorProject}', [CreatorProjectController::class, 'update'])->name('creator.projects.update');
        Route::post('creator/assets', [CreatorAssetController::class, 'store'])->name('creator.assets.store');
        Route::post('posts/{post}/creator-exports', [CreatorExportController::class, 'store'])->name('posts.creator-exports.store');
    });
});
