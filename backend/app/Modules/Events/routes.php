<?php

/*
| Routes du module Events.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Http\Middleware\EnsureModerator;
use App\Modules\Events\Http\Controllers\ModerationController;
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
            Route::delete('{uuid}/revision', [OwnEventController::class, 'withdrawRevision'])->whereUuid('uuid')->name('revision.destroy');
            Route::post('{uuid}/cancel', [OwnEventController::class, 'cancel'])->whereUuid('uuid')->name('cancel');
        });

        Route::post('{uuid}/submit', [OwnEventController::class, 'submit'])
            ->whereUuid('uuid')
            ->middleware('throttle:event-submissions')
            ->name('submit');
    });

/*
| Modération (API minimale, le back-office viendra plus tard) : rôle admin ET double
| authentification au niveau du compte et de la session (EnsureModerator). Tout autre rôle
| reçoit 404 ; un admin sans 2FA, 403. Chaque décision est limitée (event-moderation),
| journalisée (canal security) et tracée dans le journal d'audit.
*/
Route::middleware(['auth:sanctum', 'verified', EnsureModerator::class])
    ->prefix('admin')
    ->name('events.moderation.')
    ->group(function (): void {
        Route::get('events/queue', [ModerationController::class, 'queue'])->name('queue');
        Route::get('events/{uuid}', [ModerationController::class, 'showEvent'])->whereUuid('uuid')->name('events.show');
        Route::get('event-revisions/{uuid}', [ModerationController::class, 'showRevision'])->whereUuid('uuid')->name('revisions.show');

        Route::middleware('throttle:event-moderation')->group(function (): void {
            Route::post('events/{uuid}/approve', [ModerationController::class, 'approveEvent'])->whereUuid('uuid')->name('events.approve');
            Route::post('events/{uuid}/reject', [ModerationController::class, 'rejectEvent'])->whereUuid('uuid')->name('events.reject');
            Route::post('events/{uuid}/cancel', [ModerationController::class, 'cancelEvent'])->whereUuid('uuid')->name('events.cancel');
            Route::post('event-revisions/{uuid}/approve', [ModerationController::class, 'approveRevision'])->whereUuid('uuid')->name('revisions.approve');
            Route::post('event-revisions/{uuid}/reject', [ModerationController::class, 'rejectRevision'])->whereUuid('uuid')->name('revisions.reject');
        });
    });
