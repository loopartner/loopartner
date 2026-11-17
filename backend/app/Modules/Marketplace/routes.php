<?php

/*
| Routes du module Marketplace (offres, devis, comparateur).
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Modules\Marketplace\Http\Controllers\OwnOfferController;
use App\Modules\Marketplace\Http\Controllers\PublicOfferController;
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

// Offres publiques, sans authentification.
Route::get('offers', [PublicOfferController::class, 'index'])->name('marketplace.offers.index');
Route::get('offers/{slug}', [PublicOfferController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('marketplace.offers.show');
Route::get('providers/{slug}/offers', [PublicOfferController::class, 'byProvider'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('marketplace.providers.offers');
