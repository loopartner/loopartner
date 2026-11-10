<?php

/*
| Routes du noyau (hors modules), sous le préfixe /api/v1 et le middleware "api"
| (déclarés dans bootstrap/app.php). Les routes des modules sont dans app/Modules/<Module>/routes.php.
*/

use App\Http\Controllers\MediaController;
use App\Http\Controllers\ServiceCategoryController;
use Illuminate\Support\Facades\Route;

// Public, sans authentification.
Route::middleware('throttle:api')->group(function (): void {
    Route::get('categories', [ServiceCategoryController::class, 'index'])->name('categories.index');
});

Route::middleware(['throttle:api', 'auth:sanctum', 'verified'])->group(function (): void {
    Route::post('media', [MediaController::class, 'store'])
        ->middleware('throttle:media-uploads')
        ->name('media.store');
});
