<?php

use App\Http\Middleware\EnsureModerator;
use App\Http\Middleware\LimitRequestBody;
use App\Modules\Moderation\Http\Controllers\AdminModerationController;
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
        Route::get('decisions', [MyModerationController::class, 'decisions'])->name('decisions.index');
        Route::get('decisions/{uuid}', [MyModerationController::class, 'decision'])->whereUuid('uuid')->name('decisions.show');
    });

// Administration : admins avec 2FA confirmée et session ouverte avec un code (EnsureModerator :
// 404 pour les autres rôles, 403 sans 2FA). Les décisions ont leur limiteur.
Route::middleware(['auth:sanctum', 'verified', EnsureModerator::class])
    ->prefix('admin/moderation')
    ->name('moderation.admin.')
    ->group(function (): void {
        Route::get('queue', [AdminModerationController::class, 'queue'])->name('queue');
        Route::get('targets/{type}/{ref}', [AdminModerationController::class, 'target'])
            ->where(['type' => '[A-Za-z0-9_-]+', 'ref' => '[A-Za-z0-9-]+'])
            ->name('targets.show');
        Route::get('decisions/{uuid}', [AdminModerationController::class, 'decision'])->whereUuid('uuid')->name('decisions.show');

        Route::middleware('throttle:moderation-admin')->group(function (): void {
            Route::post('targets/{type}/{ref}/decisions', [AdminModerationController::class, 'decide'])
                ->where(['type' => '[A-Za-z0-9_-]+', 'ref' => '[A-Za-z0-9-]+'])
                ->name('targets.decide');
            Route::post('decisions/{uuid}/lift', [AdminModerationController::class, 'lift'])->whereUuid('uuid')->name('decisions.lift');
            Route::post('notices/{uuid}/referral', [AdminModerationController::class, 'referral'])->whereUuid('uuid')->name('notices.referral');
        });
    });
