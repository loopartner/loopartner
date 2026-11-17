<?php

/*
| Routes du module Appointments (rendez-vous).
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Modules\Appointments\Http\Controllers\AppointmentTypeController;
use App\Modules\Appointments\Http\Controllers\AvailabilityController;
use App\Modules\Appointments\Http\Controllers\CalendarController;
use Illuminate\Support\Facades\Route;

/*
| Disponibilités du prestataire connecté (CalendarPolicy : 403 pour les autres rôles) : réglages
| du calendrier, types de rendez-vous, plages hebdomadaires, exceptions, aperçu des créneaux.
*/
Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
    Route::get('me/appointments/calendar', [CalendarController::class, 'show'])->name('appointments.calendar.show');
    Route::get('me/appointments/slots', [CalendarController::class, 'slots'])
        ->middleware('throttle:appointment-slots')
        ->name('appointments.calendar.slots');
    Route::get('me/appointment-types', [AppointmentTypeController::class, 'index'])->name('appointments.types.index');
    Route::get('me/appointment-types/{uuid}', [AppointmentTypeController::class, 'show'])->whereUuid('uuid')->name('appointments.types.show');
    Route::get('me/appointments/availability/rules', [AvailabilityController::class, 'rules'])->name('appointments.availability.rules');
    Route::get('me/appointments/availability/exceptions', [AvailabilityController::class, 'exceptions'])->name('appointments.availability.exceptions');

    Route::middleware('throttle:appointment-settings-writes')->group(function (): void {
        Route::put('me/appointments/calendar', [CalendarController::class, 'update'])->name('appointments.calendar.update');
        Route::post('me/appointment-types', [AppointmentTypeController::class, 'store'])->name('appointments.types.store');
        Route::patch('me/appointment-types/{uuid}', [AppointmentTypeController::class, 'update'])->whereUuid('uuid')->name('appointments.types.update');
        Route::delete('me/appointment-types/{uuid}', [AppointmentTypeController::class, 'destroy'])->whereUuid('uuid')->name('appointments.types.destroy');
        Route::put('me/appointments/availability/rules', [AvailabilityController::class, 'replaceRules'])->name('appointments.availability.replace-rules');
        Route::post('me/appointments/availability/exceptions', [AvailabilityController::class, 'storeException'])->name('appointments.availability.store-exception');
        Route::delete('me/appointments/availability/exceptions/{uuid}', [AvailabilityController::class, 'destroyException'])->whereUuid('uuid')->name('appointments.availability.destroy-exception');
    });
});
