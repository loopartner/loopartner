<?php

/*
| Routes du module Accounts.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Modules\Accounts\Http\Controllers\MeController;
use App\Modules\Accounts\Http\Controllers\ProviderProfileController;
use App\Modules\Accounts\Http\Controllers\PublicProviderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me', [MeController::class, 'show'])->name('accounts.me.show');
    Route::patch('me', [MeController::class, 'update'])->name('accounts.me.update');
    Route::delete('me', [MeController::class, 'destroy'])
        ->middleware('throttle:account-deletion')
        ->name('accounts.me.destroy');

    Route::get('me/provider-profile', [ProviderProfileController::class, 'show'])
        ->name('accounts.provider-profile.show');
    Route::put('me/provider-profile', [ProviderProfileController::class, 'upsert'])
        ->name('accounts.provider-profile.upsert');
});

// Public, sans authentification.
Route::get('providers', [PublicProviderController::class, 'index'])->name('accounts.providers.index');
Route::get('providers/{slug}', [PublicProviderController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('accounts.providers.show');
