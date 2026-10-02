<?php

declare(strict_types=1);

use App\Http\Controllers\Brands\BrandController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
    Route::post('brands/bootstrap', [BrandController::class, 'bootstrap'])->middleware('throttle:5,1')->name('brands.bootstrap');
    Route::patch('brands', [BrandController::class, 'update'])->name('brands.update');
});
