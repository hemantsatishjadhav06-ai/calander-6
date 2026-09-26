<?php

use App\Http\Controllers\CommandSearchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Gifs\GifBrowserController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\WorkspaceMentionController;
use App\Http\Middleware\NoIndex;
use App\Services\Gifs\KlipyClient;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicPageController::class, 'home'])->name('home');

// The product pages: what the app does and how it does it. Public for the same
// reason as the home page, but unlike `/` they do not bounce signed-in users to
// the dashboard: someone already using the app may still want to read them.
Route::get('/features', [PublicPageController::class, 'features'])->name('product.features');
Route::get('/how-it-works', [PublicPageController::class, 'howItWorks'])->name('product.how-it-works');
Route::get('/platforms', [PublicPageController::class, 'platforms'])->name('product.platforms');
Route::get('/developers', [PublicPageController::class, 'developers'])->name('product.developers');
Route::get('/security', [PublicPageController::class, 'security'])->name('product.security');

// Reachable without an account on purpose. Google, Meta, X and LinkedIn all
// require a public privacy policy before approving an OAuth app, and Meta
// additionally requires a data-deletion URL, so these cannot sit behind auth.
Route::get('/privacy', [PublicPageController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [PublicPageController::class, 'terms'])->name('legal.terms');
Route::get('/data-deletion', [PublicPageController::class, 'dataDeletion'])->name('legal.data-deletion');

Route::get('/share/{token}', [PublicShareController::class, 'show'])
    ->middleware([NoIndex::class, 'throttle:30,1'])
    ->name('share.show')
    ->where('token', '[A-Za-z0-9\-]+');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('workspace-mentions', [WorkspaceMentionController::class, 'store'])->name('workspace-mentions.store');
    Route::delete('workspace-mentions/{workspaceMention}', [WorkspaceMentionController::class, 'destroy'])->name('workspace-mentions.destroy');

    // Browsing is a proxied third-party read; throttle it like the media routes.
    // whereIn('catalog', ...) below is a route constraint, so Laravel evaluates it during
    // route matching, before the `auth` middleware above ever runs. An unknown catalog
    // therefore 404s even for a guest (rather than the 401 an authenticated-only guard would
    // produce). That's acceptable: the catalog list is already public (it ships in the client
    // bundle as GIF_CATALOGS), so the 404-vs-401 distinction doesn't leak anything new.
    Route::middleware(['gifs.enabled', 'throttle:120,1'])->group(function (): void {
        Route::get('gifs/{catalog}/recent', [GifBrowserController::class, 'recent'])
            ->name('gifs.recent')
            ->whereIn('catalog', KlipyClient::CATALOGS);
        Route::get('gifs/{catalog}', [GifBrowserController::class, 'index'])
            ->name('gifs.browse')
            ->whereIn('catalog', KlipyClient::CATALOGS);
    });
});

Route::middleware('auth')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::delete('notifications', [NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::get('command-search', CommandSearchController::class)
        ->middleware('throttle:60,1')
        ->name('command-search');
});

require __DIR__.'/workspace.php';
require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
require __DIR__.'/accounts.php';
require __DIR__.'/posts.php';
require __DIR__.'/engagement.php';
require __DIR__.'/messaging.php';
require __DIR__.'/feedback.php';
