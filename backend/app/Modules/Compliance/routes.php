<?php

/*
| Routes du module Compliance.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Http\Middleware\EnsureModerator;
use App\Modules\Compliance\Http\Controllers\CatalogController;
use App\Modules\Compliance\Http\Controllers\CompanyController;
use App\Modules\Compliance\Http\Controllers\RuleAdminController;
use Illuminate\Support\Facades\Route;

/*
| Suivi administratif des prestataires et des entrepreneurs, avec un email vérifié
| (CompanyPolicy : 403 pour les autres rôles). Toutes les données d'une entreprise sont
| strictement privées : l'entreprise d'un autre utilisateur donne 404, admins compris. Chaque
| réponse contient le champ "disclaimer" (l'outil ne remplace pas un expert-comptable).
*/
Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
    // Questionnaire de démarrage : règles vérifiées seulement.
    Route::get('compliance/countries', [CatalogController::class, 'countries'])->name('compliance.countries');
    Route::get('compliance/countries/{country}/questionnaire', [CatalogController::class, 'questionnaire'])
        ->where('country', '[A-Za-z]{2}')
        ->name('compliance.questionnaire');

    Route::prefix('me/compliance')->name('compliance.own.')->group(function (): void {
        Route::get('/', [CompanyController::class, 'overview'])->name('overview');

        Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::get('companies/{company}', [CompanyController::class, 'show'])->whereUuid('company')->name('companies.show');
        Route::post('companies', [CompanyController::class, 'store'])
            ->middleware('throttle:compliance-companies')
            ->name('companies.store');
        // Suppression : confirmation récente du mot de passe exigée (423 sinon).
        Route::delete('companies/{company}', [CompanyController::class, 'destroy'])
            ->whereUuid('company')
            ->middleware(['throttle:compliance-companies', 'password.confirm'])
            ->name('companies.destroy');

        Route::middleware('throttle:compliance-writes')->group(function (): void {
            Route::patch('companies/{company}', [CompanyController::class, 'update'])->whereUuid('company')->name('companies.update');
            Route::post('companies/{company}/activate', [CompanyController::class, 'activate'])->whereUuid('company')->name('companies.activate');
        });
    });
});

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
