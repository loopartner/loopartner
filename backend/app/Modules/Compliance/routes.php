<?php

/*
| Routes du module Compliance.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Http\Middleware\EnsureModerator;
use App\Modules\Compliance\Http\Controllers\CalendarFeedController;
use App\Modules\Compliance\Http\Controllers\CatalogController;
use App\Modules\Compliance\Http\Controllers\CompanyController;
use App\Modules\Compliance\Http\Controllers\DeadlineController;
use App\Modules\Compliance\Http\Controllers\JourneyController;
use App\Modules\Compliance\Http\Controllers\RevenueController;
use App\Modules\Compliance\Http\Controllers\RuleAdminController;
use App\Modules\Compliance\Http\Controllers\SettingsController;
use App\Modules\Compliance\Http\Controllers\TaskController;
use App\Modules\Compliance\Support\CalendarFeedToken;
use Illuminate\Support\Facades\Route;

/*
| Abonnement au calendrier depuis Google Agenda, Outlook… : route PUBLIQUE (les agendas
| n'envoient pas de cookie), protégée par le jeton secret de l'URL, limitée par abonnement et
| par IP. Titres et dates des échéances seulement.
*/
Route::get('compliance/calendar/{token}.ics', [CalendarFeedController::class, 'feed'])
    ->where('token', CalendarFeedToken::PATTERN)
    ->middleware('throttle:compliance-feed')
    ->name('compliance.calendar-feed');

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
    Route::get('compliance/countries/{country}/journeys', [JourneyController::class, 'templates'])
        ->where('country', '[A-Za-z]{2}')
        ->name('compliance.journeys');

    Route::prefix('me/compliance')->name('compliance.own.')->group(function (): void {
        Route::get('/', [CompanyController::class, 'overview'])->name('overview');

        // Réglages des rappels et des alertes.
        Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::patch('settings', [SettingsController::class, 'update'])->middleware('throttle:compliance-writes')->name('settings.update');

        // URL privée d'abonnement au calendrier (montrée une seule fois à la création).
        Route::get('calendar-feed', [CalendarFeedController::class, 'show'])->name('calendar-feed.show');
        Route::post('calendar-feed', [CalendarFeedController::class, 'store'])->middleware('throttle:compliance-feed-manage')->name('calendar-feed.store');
        Route::delete('calendar-feed', [CalendarFeedController::class, 'destroy'])->middleware('throttle:compliance-feed-manage')->name('calendar-feed.destroy');

        // Export complet (portabilité RGPD).
        Route::get('export', [CompanyController::class, 'export'])->middleware('throttle:compliance-exports')->name('export');

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

        // Calendrier : toutes les entreprises, ou une entreprise.
        Route::get('deadlines', [DeadlineController::class, 'all'])->name('deadlines.all');

        Route::prefix('companies/{company}')->whereUuid('company')->group(function (): void {
            Route::get('deadlines', [DeadlineController::class, 'index'])->name('deadlines.index');
            Route::get('deadlines/{deadline}', [DeadlineController::class, 'show'])->whereUuid('deadline')->name('deadlines.show');
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('journeys', [JourneyController::class, 'index'])->name('journeys.index');

            // Chiffre d'affaires (centimes), seuils et estimation (premium).
            Route::get('revenue', [RevenueController::class, 'index'])->name('revenue.index');
            Route::get('thresholds', [RevenueController::class, 'thresholds'])->name('thresholds');
            Route::get('estimate', [RevenueController::class, 'estimate'])->name('estimate');
            Route::get('revenue.csv', [RevenueController::class, 'csv'])->middleware('throttle:compliance-exports')->name('revenue.csv');

            Route::middleware('throttle:compliance-revenue')->group(function (): void {
                Route::put('revenue/{period}', [RevenueController::class, 'save'])->where('period', '\d{4}-(0[1-9]|1[0-2])')->name('revenue.save');
                Route::delete('revenue/{period}', [RevenueController::class, 'destroy'])->where('period', '\d{4}-(0[1-9]|1[0-2])')->name('revenue.destroy');
            });
        });

        Route::middleware('throttle:compliance-writes')->group(function (): void {
            Route::patch('companies/{company}', [CompanyController::class, 'update'])->whereUuid('company')->name('companies.update');
            Route::post('companies/{company}/activate', [CompanyController::class, 'activate'])->whereUuid('company')->name('companies.activate');

            Route::prefix('companies/{company}')->whereUuid('company')->group(function (): void {
                Route::post('deadlines', [DeadlineController::class, 'store'])->name('deadlines.store');
                Route::patch('deadlines/{deadline}', [DeadlineController::class, 'update'])->whereUuid('deadline')->name('deadlines.update');
                Route::delete('deadlines/{deadline}', [DeadlineController::class, 'destroy'])->whereUuid('deadline')->name('deadlines.destroy');
                Route::post('deadlines/{deadline}/complete', [DeadlineController::class, 'complete'])->whereUuid('deadline')->name('deadlines.complete');
                Route::post('deadlines/{deadline}/skip', [DeadlineController::class, 'skip'])->whereUuid('deadline')->name('deadlines.skip');
                Route::post('deadlines/{deadline}/reopen', [DeadlineController::class, 'reopen'])->whereUuid('deadline')->name('deadlines.reopen');

                Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
                Route::patch('tasks/{task}', [TaskController::class, 'update'])->whereUuid('task')->name('tasks.update');
                Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->whereUuid('task')->name('tasks.destroy');
                Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])->whereUuid('task')->name('tasks.complete');
                Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen'])->whereUuid('task')->name('tasks.reopen');

                Route::post('journeys', [JourneyController::class, 'store'])->name('journeys.store');
                Route::delete('journeys/{journey}', [JourneyController::class, 'destroy'])->whereUuid('journey')->name('journeys.destroy');
            });
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
