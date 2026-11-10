<?php

/*
| Routes du module Directory.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Modules\Directory\Http\Controllers\ConversationController;
use App\Modules\Directory\Http\Controllers\MatchRequestController;
use App\Modules\Directory\Http\Controllers\MessageController;
use App\Modules\Directory\Http\Controllers\OwnProjectController;
use App\Modules\Directory\Http\Controllers\ProjectBrowseController;
use Illuminate\Support\Facades\Route;

// Projets publiés : prestataires connectés avec un email vérifié et un profil publié (voir
// ProjectPolicy::browse). Limite dédiée, plus stricte, et consultations journalisées.
Route::middleware(['auth:sanctum', 'verified', 'throttle:projects-browse'])->group(function (): void {
    Route::get('projects', [ProjectBrowseController::class, 'index'])->name('directory.projects.index');
    Route::get('projects/{uuid}', [ProjectBrowseController::class, 'show'])
        ->whereUuid('uuid')
        ->name('directory.projects.show');
});

// Projets de l'utilisateur connecté (particuliers et entrepreneurs, voir ProjectPolicy).
Route::middleware('auth:sanctum')
    ->prefix('me/projects')
    ->name('directory.own-projects.')
    ->group(function (): void {
        Route::get('/', [OwnProjectController::class, 'index'])->name('index');
        Route::post('/', [OwnProjectController::class, 'store'])->name('store');
        Route::get('{uuid}', [OwnProjectController::class, 'show'])->whereUuid('uuid')->name('show');
        Route::patch('{uuid}', [OwnProjectController::class, 'update'])->whereUuid('uuid')->name('update');
        Route::delete('{uuid}', [OwnProjectController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
        Route::post('{uuid}/publish', [OwnProjectController::class, 'publish'])->whereUuid('uuid')->name('publish');
        Route::post('{uuid}/close', [OwnProjectController::class, 'close'])->whereUuid('uuid')->name('close');
    });

/*
| Mise en relation et messagerie : utilisateurs connectés avec un email vérifié. Seuls les deux
| participants d'une demande ou d'une conversation y ont accès (404 pour tout autre utilisateur).
*/
Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
    // Envoi d'une demande (limite dédiée : signe de spam au-delà).
    Route::middleware('throttle:match-requests')->group(function (): void {
        Route::post('projects/{uuid}/proposals', [MatchRequestController::class, 'propose'])
            ->whereUuid('uuid')
            ->name('directory.proposals.store');
        Route::post('providers/{slug}/contact', [MatchRequestController::class, 'contact'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->name('directory.contacts.store');
    });

    Route::prefix('me/match-requests')->name('directory.match-requests.')->group(function (): void {
        Route::get('/', [MatchRequestController::class, 'index'])->name('index');
        Route::get('{uuid}', [MatchRequestController::class, 'show'])->whereUuid('uuid')->name('show');
        Route::post('{uuid}/accept', [MatchRequestController::class, 'accept'])->whereUuid('uuid')->name('accept');
        Route::post('{uuid}/decline', [MatchRequestController::class, 'decline'])->whereUuid('uuid')->name('decline');
        Route::post('{uuid}/withdraw', [MatchRequestController::class, 'withdraw'])->whereUuid('uuid')->name('withdraw');
        Route::post('{uuid}/report', [MatchRequestController::class, 'report'])
            ->whereUuid('uuid')
            ->middleware('throttle:reports')
            ->name('report');
        Route::post('{uuid}/block', [MatchRequestController::class, 'block'])
            ->whereUuid('uuid')
            ->middleware('throttle:blocks')
            ->name('block');
    });

    Route::prefix('me/conversations')->name('directory.conversations.')->group(function (): void {
        Route::get('/', [ConversationController::class, 'index'])->name('index');
        Route::get('unread', [ConversationController::class, 'unread'])->name('unread');
        Route::get('{uuid}', [ConversationController::class, 'show'])->whereUuid('uuid')->name('show');
        Route::post('{uuid}/block', [ConversationController::class, 'block'])
            ->whereUuid('uuid')
            ->middleware('throttle:blocks')
            ->name('block');
        Route::delete('{uuid}/block', [ConversationController::class, 'unblock'])
            ->whereUuid('uuid')
            ->middleware('throttle:blocks')
            ->name('unblock');
        Route::post('{uuid}/read', [MessageController::class, 'read'])->whereUuid('uuid')->name('read');

        Route::get('{uuid}/messages', [MessageController::class, 'index'])->whereUuid('uuid')->name('messages.index');
        Route::post('{uuid}/messages', [MessageController::class, 'store'])
            ->whereUuid('uuid')
            ->middleware('throttle:messages')
            ->name('messages.store');
        Route::post('{uuid}/messages/{messageUuid}/report', [MessageController::class, 'report'])
            ->whereUuid(['uuid', 'messageUuid'])
            ->middleware('throttle:reports')
            ->name('messages.report');
    });
});
