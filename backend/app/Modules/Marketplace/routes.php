<?php

/*
| Routes du module Marketplace (offres, devis, comparateur).
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Modules\Marketplace\Http\Controllers\SellerProfileController;
use Illuminate\Support\Facades\Route;

// Identité professionnelle du prestataire connecté.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me/seller-profile', [SellerProfileController::class, 'show'])->name('marketplace.seller-profile.show');
    Route::put('me/seller-profile', [SellerProfileController::class, 'upsert'])
        ->middleware('throttle:seller-profile-writes')
        ->name('marketplace.seller-profile.upsert');
});
