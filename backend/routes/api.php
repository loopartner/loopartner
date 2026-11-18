<?php

/*
| Routes du noyau (hors modules), sous le préfixe /api/v1 et le middleware "api"
| (déclarés dans bootstrap/app.php). Les routes des modules sont dans app/Modules/<Module>/routes.php.
*/

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AgendaFeedController;
use App\Http\Controllers\AiUsageController;
use App\Http\Controllers\LegalConsentController;
use App\Http\Controllers\LegalDocumentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ServiceCategoryController;
use App\Http\Middleware\EnsureModerator;
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

/*
| Parcours d'accueil par rôle (lot 5) : sans email vérifié (sa vérification est une étape).
*/
Route::middleware(['throttle:api', 'auth:sanctum'])->group(function (): void {
    Route::get('me/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('me/onboarding/dismiss', [OnboardingController::class, 'dismiss'])
        ->middleware('throttle:onboarding-writes')
        ->name('onboarding.dismiss');
    Route::post('me/onboarding/steps/{key}/skip', [OnboardingController::class, 'skip'])
        ->where('key', '[a-z_]{1,64}')
        ->middleware('throttle:onboarding-writes')
        ->name('onboarding.skip');
});

/*
| Suivi des coûts de l'IA (lot 5) : admins avec 2FA de session (EnsureModerator) ; totaux sans
| identité ni contenu.
*/
Route::middleware(['throttle:api', 'auth:sanctum', 'verified', EnsureModerator::class, 'throttle:ai-admin'])->group(function (): void {
    Route::get('admin/ai/usage', AiUsageController::class)->name('admin.ai.usage');
});
