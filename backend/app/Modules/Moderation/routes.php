<?php

use App\Http\Middleware\LimitRequestBody;
use App\Modules\Moderation\Http\Controllers\MyModerationController;
use App\Modules\Moderation\Http\Controllers\PublicModerationController;
use Illuminate\Support\Facades\Route;

/*
| Module Moderation (DSA). Préfixe /api/v1, middleware api et throttle:api (ModuleServiceProvider).
*/

// Public : informations, signalement (avec ou sans compte), suivi par jeton.
Route::get('moderation/info', [PublicModerationController::class, 'info'])->name('moderation.info');
Route::post('moderation/notices', [PublicModerationController::class, 'store'])
    ->middleware(['throttle:moderation-notices', LimitRequestBody::class.':32768'])
    ->name('moderation.notices.store');
Route::post('moderation/notices/{uuid}/lookup', [PublicModerationController::class, 'lookup'])
    ->whereUuid('uuid')
    ->middleware('throttle:moderation-lookups')
    ->name('moderation.notices.lookup');

// Utilisateur connecté (email vérifié non requis : on doit pouvoir suivre une décision).
Route::middleware('auth:sanctum')
    ->prefix('me/moderation')
    ->name('moderation.me.')
    ->group(function (): void {
        Route::get('notices', [MyModerationController::class, 'notices'])->name('notices.index');
        Route::get('notices/{uuid}', [MyModerationController::class, 'notice'])->whereUuid('uuid')->name('notices.show');
    });
