<?php

declare(strict_types=1);

use App\Http\Controllers\Scheduling\EditorialScheduleController;
use App\Models\EditorialQueue;
use App\Models\EditorialQueueEntry;
use App\Models\RecurringPostSeries;
use Illuminate\Support\Facades\Route;

Route::bind('recurringSeries', fn (string $id): RecurringPostSeries => RecurringPostSeries::query()->where('workspace_id', request()->user()?->current_workspace_id)->findOrFail($id));
Route::bind('editorialQueue', fn (string $id): EditorialQueue => EditorialQueue::query()->where('workspace_id', request()->user()?->current_workspace_id)->findOrFail($id));
Route::bind('editorialEntry', fn (string $id): EditorialQueueEntry => EditorialQueueEntry::query()->where('workspace_id', request()->user()?->current_workspace_id)->findOrFail($id));

Route::middleware(['auth', 'verified'])->prefix('scheduling')->name('scheduling.')->group(function (): void {
    Route::get('/', [EditorialScheduleController::class, 'index'])->name('index');
    Route::post('series', [EditorialScheduleController::class, 'storeSeries'])->name('series.store');
    Route::put('series/{recurringSeries}', [EditorialScheduleController::class, 'updateSeries'])->name('series.update');
    Route::post('series/{recurringSeries}/state', [EditorialScheduleController::class, 'seriesState'])->name('series.state');
    Route::post('series/{recurringSeries}/generate', [EditorialScheduleController::class, 'generate'])->middleware('throttle:10,1')->name('series.generate');
    Route::post('queues', [EditorialScheduleController::class, 'storeQueue'])->name('queues.store');
    Route::put('queues/{editorialQueue}', [EditorialScheduleController::class, 'updateQueue'])->name('queues.update');
    Route::post('queues/{editorialQueue}/state', [EditorialScheduleController::class, 'queueState'])->name('queues.state');
    Route::post('queues/{editorialQueue}/entries', [EditorialScheduleController::class, 'enqueue'])->name('queues.enqueue');
    Route::delete('entries/{editorialEntry}', [EditorialScheduleController::class, 'remove'])->name('entries.destroy');
    Route::post('fill', [EditorialScheduleController::class, 'fill'])->middleware('throttle:10,1')->name('fill');
});
