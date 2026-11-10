<?php

/*
| Routes du module Directory.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

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
