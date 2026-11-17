<?php

/*
| Routes du noyau (hors modules), sous le préfixe /api/v1 et le middleware "api"
| (déclarés dans bootstrap/app.php). Les routes des modules sont dans app/Modules/<Module>/routes.php.
*/

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AgendaFeedController;
use App\Http\Controllers\LegalConsentController;
use App\Http\Controllers\LegalDocumentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\ServiceCategoryController;
use App\Support\Calendar\FeedToken;
use Illuminate\Support\Facades\Route;

// Public, sans authentification.
Route::middleware('throttle:api')->group(function (): void {
    Route::get('categories', [ServiceCategoryController::class, 'index'])->name('categories.index');

    // Documents légaux versionnés (CGU, politique de confidentialité).
    Route::get('legal/documents', [LegalDocumentController::class, 'index'])->name('legal.documents.index');
    Route::get('legal/documents/{type}', [LegalDocumentController::class, 'show'])
        ->where('type', LegalDocumentController::routeConstraint())
        ->name('legal.documents.show');
});

// Consentements : permis même quand une nouvelle version doit être acceptée (EnsureLegalConsent).
Route::middleware(['throttle:api', 'auth:sanctum'])->group(function (): void {
    Route::get('me/legal', [LegalConsentController::class, 'show'])->name('legal.me.show');
    Route::post('me/legal/accept', [LegalConsentController::class, 'accept'])
        ->middleware('throttle:legal-acceptances')
        ->name('legal.me.accept');
});

// Préférences de notifications : sans email vérifié aussi (l'utilisateur règle ce qu'il reçoit).
Route::middleware(['throttle:api', 'auth:sanctum'])->group(function (): void {
    Route::get('me/notification-preferences', [NotificationPreferenceController::class, 'show'])
        ->name('notification-preferences.show');
    Route::put('me/notification-preferences', [NotificationPreferenceController::class, 'update'])
        ->name('notification-preferences.update');
});

Route::middleware(['throttle:api', 'auth:sanctum', 'verified'])->group(function (): void {
    Route::post('media', [MediaController::class, 'store'])
        ->middleware('throttle:media-uploads')
        ->name('media.store');
});

/*
| Agenda unifié (lot 4) : rendez-vous, événements suivis ou organisés, échéances. Abonnement
| unique : route publique protégée par son jeton secret (titres et dates seulement).
*/
Route::get('agenda/{token}.ics', [AgendaFeedController::class, 'feed'])
    ->where('token', FeedToken::PATTERN)
    ->middleware(['throttle:api', 'throttle:agenda-feed'])
    ->name('agenda.feed');

Route::middleware(['throttle:api', 'auth:sanctum', 'verified'])->group(function (): void {
    Route::get('me/agenda', [AgendaController::class, 'index'])
        ->middleware('throttle:agenda')
        ->name('agenda.index');
    Route::get('me/agenda/feed', [AgendaFeedController::class, 'show'])->name('agenda.feed.show');
    Route::post('me/agenda/feed', [AgendaFeedController::class, 'store'])
        ->middleware('throttle:agenda-feed-manage')
        ->name('agenda.feed.store');
    Route::delete('me/agenda/feed', [AgendaFeedController::class, 'destroy'])
        ->middleware('throttle:agenda-feed-manage')
        ->name('agenda.feed.destroy');
});
