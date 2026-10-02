<?php

declare(strict_types=1);

use App\Http\Controllers\Blogs\BlogDraftController;
use App\Http\Middleware\NoIndex;
use App\Models\BlogDraft;
use Illuminate\Support\Facades\Route;

Route::bind('blogDraft', fn (string $value): BlogDraft => BlogDraft::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->whereKey($value)
    ->firstOrFail());

Route::middleware(['auth', 'verified', NoIndex::class, 'cache.headers:private;no_store'])->group(function (): void {
    Route::get('blogs', [BlogDraftController::class, 'index'])->name('blogs.index');
    Route::get('blogs/create', [BlogDraftController::class, 'create'])->name('blogs.create');
    Route::post('blogs', [BlogDraftController::class, 'store'])->name('blogs.store');
    Route::get('blogs/{blogDraft}/edit', [BlogDraftController::class, 'edit'])->name('blogs.edit');
    Route::patch('blogs/{blogDraft}', [BlogDraftController::class, 'update'])->name('blogs.update');
    Route::get('blogs/{blogDraft}/preview', [BlogDraftController::class, 'preview'])->name('blogs.preview');
    Route::post('blogs/{blogDraft}/request-review', [BlogDraftController::class, 'requestReview'])->name('blogs.request-review');
    Route::post('blogs/{blogDraft}/approve', [BlogDraftController::class, 'approve'])->name('blogs.approve');
    Route::post('blogs/{blogDraft}/reject', [BlogDraftController::class, 'reject'])->name('blogs.reject');
    Route::post('blogs/{blogDraft}/publish', [BlogDraftController::class, 'publish'])->name('blogs.publish');
});
