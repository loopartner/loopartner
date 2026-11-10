<?php

/*
| Routes du module Accounts.
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Modules\Accounts\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me', [MeController::class, 'show'])->name('accounts.me.show');
    Route::patch('me', [MeController::class, 'update'])->name('accounts.me.update');
    Route::delete('me', [MeController::class, 'destroy'])
        ->middleware('throttle:6,1')
        ->name('accounts.me.destroy');
});
