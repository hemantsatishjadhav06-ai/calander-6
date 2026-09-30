<?php

declare(strict_types=1);

use App\Http\Controllers\Reviews\ReviewWorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('reviews')->name('reviews.')->group(function (): void {
    Route::get('/', [ReviewWorkflowController::class, 'index'])->name('index');
    Route::put('/workflow', [ReviewWorkflowController::class, 'configure'])->name('configure');
    Route::post('/posts/{post}/actions', [ReviewWorkflowController::class, 'act'])->middleware('throttle:60,1')->name('act');
    Route::put('/posts/{post}/client', [ReviewWorkflowController::class, 'assign'])->name('assign');
    Route::get('/posts/{post}/history', [ReviewWorkflowController::class, 'history'])->name('history');
});
