<?php

/*
| Routes du module Appointments (rendez-vous).
| Chargées par App\Providers\ModuleServiceProvider sous le préfixe /api/v1,
| avec les middlewares "api" et "throttle:api".
*/

use App\Http\Middleware\LimitRequestBody;
use App\Modules\Appointments\Http\Controllers\AppointmentController;
use App\Modules\Appointments\Http\Controllers\AppointmentTypeController;
use App\Modules\Appointments\Http\Controllers\AvailabilityController;
use App\Modules\Appointments\Http\Controllers\CalendarController;
use App\Modules\Appointments\Http\Controllers\RelationAppointmentController;
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

/*
| Rendez-vous dans une demande de mise en relation acceptée (décision 52) : ses deux
| participants seulement (404 pour tout autre utilisateur, admins compris).
*/
Route::middleware(['auth:sanctum', 'verified'])
    ->prefix('me/match-requests/{uuid}')
    ->whereUuid('uuid')
    ->name('appointments.relation.')
    ->group(function (): void {
        Route::get('booking', [RelationAppointmentController::class, 'booking'])
            ->middleware('throttle:appointment-slots')
            ->name('booking');
        Route::get('appointments', [RelationAppointmentController::class, 'index'])->name('index');
        Route::post('appointments', [RelationAppointmentController::class, 'store'])
            ->middleware(['throttle:appointment-bookings', LimitRequestBody::class.':32768'])
            ->name('store');
    });

/*
| Rendez-vous de l'utilisateur connecté (client ou prestataire) : ses deux parties seulement.
*/
Route::middleware(['auth:sanctum', 'verified'])
    ->prefix('me/appointments')
    ->name('appointments.')
    ->group(function (): void {
        Route::get('/', [AppointmentController::class, 'index'])->name('index');
        Route::get('{uuid}', [AppointmentController::class, 'show'])->whereUuid('uuid')->name('show');

        Route::middleware(['throttle:appointment-decisions', LimitRequestBody::class.':32768'])->group(function (): void {
            Route::post('{uuid}/confirm', [AppointmentController::class, 'confirm'])->whereUuid('uuid')->name('confirm');
            Route::post('{uuid}/decline', [AppointmentController::class, 'decline'])->whereUuid('uuid')->name('decline');
            Route::post('{uuid}/cancel', [AppointmentController::class, 'cancel'])->whereUuid('uuid')->name('cancel');
            Route::post('{uuid}/accept-change', [AppointmentController::class, 'acceptChange'])->whereUuid('uuid')->name('accept-change');
            Route::post('{uuid}/decline-change', [AppointmentController::class, 'declineChange'])->whereUuid('uuid')->name('decline-change');
        });

        Route::post('{uuid}/reschedule', [AppointmentController::class, 'reschedule'])
            ->whereUuid('uuid')
            ->middleware(['throttle:appointment-bookings', LimitRequestBody::class.':32768'])
            ->name('reschedule');
    });
