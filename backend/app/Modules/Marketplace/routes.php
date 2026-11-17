<?php

/*
| Routes du module Marketplace (offres, devis, comparateur).
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Http\Middleware\LimitRequestBody;
use App\Modules\Marketplace\Http\Controllers\OwnOfferController;
use App\Modules\Marketplace\Http\Controllers\ProjectQuotesController;
use App\Modules\Marketplace\Http\Controllers\PublicOfferController;
use App\Modules\Marketplace\Http\Controllers\QuoteController;
use App\Modules\Marketplace\Http\Controllers\SellerProfileController;
use Illuminate\Support\Facades\Route;

// Identité professionnelle du prestataire connecté.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me/seller-profile', [SellerProfileController::class, 'show'])->name('marketplace.seller-profile.show');
    Route::put('me/seller-profile', [SellerProfileController::class, 'upsert'])
        ->middleware('throttle:seller-profile-writes')
        ->name('marketplace.seller-profile.upsert');
});

// Offres du prestataire connecté (publiées sans validation préalable, signalables).
Route::middleware('auth:sanctum')
    ->prefix('me/offers')
    ->name('marketplace.own-offers.')
    ->group(function (): void {
        Route::get('/', [OwnOfferController::class, 'index'])->name('index');
        Route::get('{uuid}', [OwnOfferController::class, 'show'])->whereUuid('uuid')->name('show');

        Route::middleware('throttle:offer-writes')->group(function (): void {
            Route::post('/', [OwnOfferController::class, 'store'])->name('store');
            Route::patch('{uuid}', [OwnOfferController::class, 'update'])->whereUuid('uuid')->name('update');
            Route::delete('{uuid}', [OwnOfferController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
            Route::post('{uuid}/publish', [OwnOfferController::class, 'publish'])->whereUuid('uuid')->name('publish');
            Route::post('{uuid}/unpublish', [OwnOfferController::class, 'unpublish'])->whereUuid('uuid')->name('unpublish');
        });
    });

/*
| Devis : seulement dans une demande de mise en relation acceptée (décision 36). Utilisateurs
| connectés avec un email vérifié ; seules les deux parties voient un devis (404 sinon).
*/
Route::middleware(['auth:sanctum', 'verified'])
    ->prefix('me/quotes')
    ->name('marketplace.quotes.')
    ->group(function (): void {
        Route::get('/', [QuoteController::class, 'index'])->name('index');
        Route::get('{uuid}', [QuoteController::class, 'show'])->whereUuid('uuid')->name('show');

        Route::middleware(['throttle:quote-writes', LimitRequestBody::class.':131072'])->group(function (): void {
            Route::post('/', [QuoteController::class, 'store'])->name('store');
            Route::put('{uuid}', [QuoteController::class, 'update'])->whereUuid('uuid')->name('update');
        });

        Route::middleware('throttle:quote-writes')->group(function (): void {
            Route::delete('{uuid}', [QuoteController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
            Route::post('{uuid}/revise', [QuoteController::class, 'revise'])->whereUuid('uuid')->name('revise');
            Route::post('{uuid}/withdraw', [QuoteController::class, 'withdraw'])->whereUuid('uuid')->name('withdraw');
        });

        Route::middleware('throttle:quote-decisions')->group(function (): void {
            Route::post('{uuid}/accept', [QuoteController::class, 'accept'])->whereUuid('uuid')->name('accept');
            Route::post('{uuid}/decline', [QuoteController::class, 'decline'])->whereUuid('uuid')->name('decline');
            Route::post('{uuid}/withdrawal', [QuoteController::class, 'withdrawal'])->whereUuid('uuid')->name('withdrawal');
        });

        Route::post('{uuid}/report', [QuoteController::class, 'report'])
            ->whereUuid('uuid')
            ->middleware('throttle:reports')
            ->name('report');

        Route::get('{uuid}/pdf', [QuoteController::class, 'pdf'])
            ->whereUuid('uuid')
            ->middleware('throttle:quote-pdfs')
            ->name('pdf');

        Route::post('{uuid}/send', [QuoteController::class, 'send'])
            ->whereUuid('uuid')
            ->middleware('throttle:quote-sends')
            ->name('send');
    });

/*
| Devis reçus pour un projet du porteur connecté : liste gratuite ; comparaison et export PDF
| Premium (décisions 33 et 43). Le projet d'un autre utilisateur donne 404.
*/
Route::middleware(['auth:sanctum', 'verified'])
    ->prefix('me/projects/{uuid}/quotes')
    ->whereUuid('uuid')
    ->name('marketplace.project-quotes.')
    ->group(function (): void {
        Route::get('/', [ProjectQuotesController::class, 'index'])->name('index');
        Route::get('comparison', [ProjectQuotesController::class, 'comparison'])
            ->middleware('throttle:quote-comparisons')
            ->name('comparison');
        Route::get('comparison.pdf', [ProjectQuotesController::class, 'comparisonPdf'])
            ->middleware('throttle:quote-pdfs')
            ->name('comparison-pdf');
    });

// Offres publiques, sans authentification.
Route::get('offers', [PublicOfferController::class, 'index'])->name('marketplace.offers.index');
Route::get('offers/{slug}', [PublicOfferController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('marketplace.offers.show');
Route::get('providers/{slug}/offers', [PublicOfferController::class, 'byProvider'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('marketplace.providers.offers');
