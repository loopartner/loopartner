<?php

/*
| Routes du module Compliance.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Http\Middleware\EnsureModerator;
use App\Modules\Compliance\Http\Controllers\RuleAdminController;
use Illuminate\Support\Facades\Route;

/*
| Administration des règles fiscales et sociales : admins seulement, avec la double
| authentification au niveau du compte et de la session (EnsureModerator : 404 pour les autres
| rôles, 403 sans 2FA). Chaque écriture est limitée (compliance-rules-admin), journalisée
| (canal security) et tracée dans le journal d'audit. Les admins n'ont aucun accès aux données
| des entreprises.
*/
Route::middleware(['auth:sanctum', 'verified', EnsureModerator::class])
    ->prefix('admin/compliance')
    ->name('compliance.admin.')
    ->group(function (): void {
        Route::get('rules', [RuleAdminController::class, 'index'])->name('rules.index');
        Route::get('rules/{uuid}', [RuleAdminController::class, 'show'])->whereUuid('uuid')->name('rules.show');
        Route::get('coverage', [RuleAdminController::class, 'coverage'])->name('coverage');

        Route::middleware('throttle:compliance-rules-admin')->group(function (): void {
            Route::post('rules', [RuleAdminController::class, 'store'])->name('rules.store');
            Route::patch('rules/{uuid}', [RuleAdminController::class, 'update'])->whereUuid('uuid')->name('rules.update');
            Route::delete('rules/{uuid}', [RuleAdminController::class, 'destroy'])->whereUuid('uuid')->name('rules.destroy');
            Route::post('rules/{uuid}/revise', [RuleAdminController::class, 'revise'])->whereUuid('uuid')->name('rules.revise');
            Route::post('rules/{uuid}/verify', [RuleAdminController::class, 'verify'])->whereUuid('uuid')->name('rules.verify');
            Route::post('rules/{uuid}/retire', [RuleAdminController::class, 'retire'])->whereUuid('uuid')->name('rules.retire');
        });
    });
