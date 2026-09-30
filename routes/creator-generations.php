<?php

declare(strict_types=1);

use App\Http\Controllers\Creator\CreatorGenerationController;
use App\Models\CreatorGeneration;
use Illuminate\Support\Facades\Route;

Route::bind('creatorGeneration', fn (string $value): CreatorGeneration => CreatorGeneration::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)->whereKey($value)->firstOrFail());

Route::middleware(['auth', 'verified', 'throttle:90,1'])->prefix('creator')->name('creator.')->group(function (): void {
    Route::get('capabilities', [CreatorGenerationController::class, 'capabilities'])->name('capabilities');
    Route::get('generations', [CreatorGenerationController::class, 'index'])->name('generations.index');
    Route::post('generations/quote', [CreatorGenerationController::class, 'quote'])->middleware('throttle:20,1')->name('generations.quote');
    Route::post('generations/{creatorGeneration}/confirm', [CreatorGenerationController::class, 'confirm'])->middleware('throttle:10,1')->name('generations.confirm');
    Route::get('generations/{creatorGeneration}', [CreatorGenerationController::class, 'show'])->name('generations.show');
    Route::post('generations/{creatorGeneration}/cancel', [CreatorGenerationController::class, 'cancel'])->name('generations.cancel');
});
