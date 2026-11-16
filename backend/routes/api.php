<?php

/*
| Routes du noyau (hors modules), sous le préfixe /api/v1 et le middleware "api"
| (déclarés dans bootstrap/app.php). Les routes des modules sont dans app/Modules/<Module>/routes.php.
*/

use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\ServiceCategoryController;
use Illuminate\Support\Facades\Route;

// Public, sans authentification.
Route::middleware('throttle:api')->group(function (): void {
    Route::get('categories', [ServiceCategoryController::class, 'index'])->name('categories.index');
});

// Préférences de notifications : sans email vérifié aussi (l'utilisateur règle ce qu'il reçoit).
Route::middleware(['throttle:api', 'auth:sanctum'])->group(function (): void {
    Route::get('me/notification-preferences', [NotificationPreferenceController::class, 'show'])
        ->name('notification-preferences.show');
    Route::put('me/notification-preferences', [NotificationPreferenceController::class, 'update'])
        ->name('notification-preferences.update');
});

Route::middleware(['throttle:api', 'auth:sanctum', 'verified'])->group(function (): void {
    Route::post('media', [MediaController::class, 'store'])
        ->middleware('throttle:media-uploads')
        ->name('media.store');
});
