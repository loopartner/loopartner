<?php

/*
| Routes du module Events.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Modules\Events\Http\Controllers\OwnEventController;
use Illuminate\Support\Facades\Route;

/*
| Événements de l'organisateur connecté (prestataires avec un profil publié, entrepreneurs,
| rédacteurs, admins), avec un email vérifié. L'événement d'un autre utilisateur donne 404.
*/
Route::middleware(['auth:sanctum', 'verified'])
    ->prefix('me/events')
    ->name('events.own.')
    ->group(function (): void {
        Route::get('/', [OwnEventController::class, 'index'])->name('index');
        Route::get('{uuid}', [OwnEventController::class, 'show'])->whereUuid('uuid')->name('show');

        Route::middleware('throttle:event-writes')->group(function (): void {
            Route::post('/', [OwnEventController::class, 'store'])->name('store');
            Route::patch('{uuid}', [OwnEventController::class, 'update'])->whereUuid('uuid')->name('update');
            Route::delete('{uuid}', [OwnEventController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
            Route::post('{uuid}/withdraw', [OwnEventController::class, 'withdraw'])->whereUuid('uuid')->name('withdraw');
            Route::post('{uuid}/cancel', [OwnEventController::class, 'cancel'])->whereUuid('uuid')->name('cancel');
        });

        Route::post('{uuid}/submit', [OwnEventController::class, 'submit'])
            ->whereUuid('uuid')
            ->middleware('throttle:event-submissions')
            ->name('submit');
    });
