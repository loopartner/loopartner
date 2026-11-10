<?php

/*
| Routes du module Accounts.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Modules\Accounts\Http\Controllers\EmailVerificationController;
use App\Modules\Accounts\Http\Controllers\MeController;
use App\Modules\Accounts\Http\Controllers\ProviderProfileController;
use App\Modules\Accounts\Http\Controllers\PublicProviderController;
use App\Modules\Accounts\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

// Inscription et vérification de l'email, qui remplacent celles de Fortify : réponse identique que
// l'adresse soit libre ou non, sans connexion automatique (voir CLAUDE.md, « Parcours d'inscription »).
Route::prefix('auth')->group(function (): void {
    Route::post('register', RegisterController::class)
        ->middleware('throttle:register')
        ->name('accounts.register');
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')
        ->where('hash', '[0-9a-f]{40}')
        ->middleware('throttle:verification')
        ->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware(['auth:sanctum', 'throttle:verification'])
        ->name('verification.send');
});

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
