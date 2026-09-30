<?php

declare(strict_types=1);

use App\Http\Controllers\Integrations\AirtableController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('airtable', [AirtableController::class, 'index'])->name('airtable.index');
    Route::put('airtable', [AirtableController::class, 'update'])->name('airtable.update');
    Route::post('airtable/sync', [AirtableController::class, 'sync'])->middleware('throttle:2,1')->name('airtable.sync');
    Route::post('posts/{post}/review', [AirtableController::class, 'review'])->name('posts.review');
    Route::post('airtable/posts/{post}/resolve', [AirtableController::class, 'resolve'])->name('airtable.resolve');
});
