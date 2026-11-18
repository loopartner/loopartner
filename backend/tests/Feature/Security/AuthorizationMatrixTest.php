<?php

use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
| Matrice d'autorisation transversale (OWASP API1 BOLA, API3 propriétés, API5 BFLA).
|
| ROUTES est l'inventaire de toutes les routes de l'API : une nouvelle route fait échouer le test
| tant qu'elle n'y est pas classée (publique ou authentifiée). Chaque route authentifiée doit
| répondre 401 à un visiteur anonyme.
*/

/**
 * "MÉTHODE uri" => "public" ou "auth".
 */
const API_ROUTES = [
    'GET api/v1/categories' => 'public',
    'GET api/v1/legal/documents' => 'public',
    'GET api/v1/legal/documents/{type}' => 'public',
    'GET api/v1/providers' => 'public',
    'GET api/v1/providers/{slug}' => 'public',
    'GET api/v1/events' => 'public',
    'GET api/v1/events/{slug}' => 'public',
    'GET api/v1/events/{slug}/calendar.ics' => 'public',
    'POST api/v1/auth/register' => 'public',
    'GET api/v1/auth/email/verify/{id}/{hash}' => 'public',
    'POST api/v1/auth/login' => 'public',
    'POST api/v1/auth/forgot-password' => 'public',
    'POST api/v1/auth/reset-password' => 'public',
    'POST api/v1/auth/two-factor-challenge' => 'public',
    'POST api/v1/auth/logout' => 'auth',
    'POST api/v1/auth/email/verification-notification' => 'auth',
    'PUT api/v1/auth/user/password' => 'auth',
    'POST api/v1/auth/user/confirm-password' => 'auth',
    'GET api/v1/auth/user/confirmed-password-status' => 'auth',
    'POST api/v1/auth/user/two-factor-authentication' => 'auth',
    'DELETE api/v1/auth/user/two-factor-authentication' => 'auth',
    'POST api/v1/auth/user/confirmed-two-factor-authentication' => 'auth',
    'GET api/v1/auth/user/two-factor-qr-code' => 'auth',
    'GET api/v1/auth/user/two-factor-secret-key' => 'auth',
    'GET api/v1/auth/user/two-factor-recovery-codes' => 'auth',
    'POST api/v1/auth/user/two-factor-recovery-codes' => 'auth',
    'GET api/v1/me' => 'auth',
    'PATCH api/v1/me' => 'auth',
    'DELETE api/v1/me' => 'auth',
    'GET api/v1/me/entitlements' => 'auth',
    'GET api/v1/me/legal' => 'auth',
    'POST api/v1/me/legal/accept' => 'auth',
    'GET api/v1/me/notification-preferences' => 'auth',
    'PUT api/v1/me/notification-preferences' => 'auth',
    'GET api/v1/me/provider-profile' => 'auth',
    'PUT api/v1/me/provider-profile' => 'auth',
    'POST api/v1/media' => 'auth',
    'GET api/v1/me/projects' => 'auth',
    'POST api/v1/me/projects' => 'auth',
    'GET api/v1/me/projects/{uuid}' => 'auth',
    'PATCH api/v1/me/projects/{uuid}' => 'auth',
    'DELETE api/v1/me/projects/{uuid}' => 'auth',
    'POST api/v1/me/projects/{uuid}/publish' => 'auth',
    'POST api/v1/me/projects/{uuid}/close' => 'auth',
    'GET api/v1/projects' => 'auth',
    'GET api/v1/projects/{uuid}' => 'auth',
    // Mise en relation et messagerie (voir aussi MatchingAuthorizationTest).
    'POST api/v1/projects/{uuid}/proposals' => 'auth',
    'POST api/v1/providers/{slug}/contact' => 'auth',
    'GET api/v1/me/match-requests' => 'auth',
    'GET api/v1/me/match-requests/{uuid}' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/accept' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/decline' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/withdraw' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/report' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/block' => 'auth',
    'GET api/v1/me/conversations' => 'auth',
    'GET api/v1/me/conversations/unread' => 'auth',
    'GET api/v1/me/conversations/{uuid}' => 'auth',
    'POST api/v1/me/conversations/{uuid}/block' => 'auth',
    'DELETE api/v1/me/conversations/{uuid}/block' => 'auth',
    'POST api/v1/me/conversations/{uuid}/read' => 'auth',
    'GET api/v1/me/conversations/{uuid}/messages' => 'auth',
    'POST api/v1/me/conversations/{uuid}/messages' => 'auth',
    'POST api/v1/me/conversations/{uuid}/messages/{messageUuid}/report' => 'auth',
    // Événements : organisateur (voir aussi EventsAuthorizationTest).
    'GET api/v1/me/events' => 'auth',
    'POST api/v1/me/events' => 'auth',
    'GET api/v1/me/events/{uuid}' => 'auth',
    'PATCH api/v1/me/events/{uuid}' => 'auth',
    'DELETE api/v1/me/events/{uuid}' => 'auth',
    'POST api/v1/me/events/{uuid}/submit' => 'auth',
    'POST api/v1/me/events/{uuid}/withdraw' => 'auth',
    'DELETE api/v1/me/events/{uuid}/revision' => 'auth',
    'POST api/v1/me/events/{uuid}/cancel' => 'auth',
    'GET api/v1/me/events/{uuid}/registrations' => 'auth',
    'DELETE api/v1/me/events/{uuid}/registrations/{registrationUuid}' => 'auth',
    'GET api/v1/me/events/{uuid}/announcements' => 'auth',
    'POST api/v1/me/events/{uuid}/announcements' => 'auth',
    // Événements : inscriptions.
    'POST api/v1/events/{slug}/registration' => 'auth',
    'DELETE api/v1/events/{slug}/registration' => 'auth',
    'GET api/v1/me/event-registrations' => 'auth',
    'GET api/v1/me/event-registrations/{slug}' => 'auth',
    'GET api/v1/me/event-registrations/{slug}/calendar.ics' => 'auth',
    // Modération des événements : admin avec double authentification (EnsureModerator).
    'GET api/v1/admin/events/queue' => 'auth',
    'GET api/v1/admin/events/{uuid}' => 'auth',
    'POST api/v1/admin/events/{uuid}/approve' => 'auth',
    'POST api/v1/admin/events/{uuid}/reject' => 'auth',
    'POST api/v1/admin/events/{uuid}/cancel' => 'auth',
    'GET api/v1/admin/event-revisions/{uuid}' => 'auth',
    'POST api/v1/admin/event-revisions/{uuid}/approve' => 'auth',
    'POST api/v1/admin/event-revisions/{uuid}/reject' => 'auth',
    'GET api/v1/admin/event-announcements/{uuid}' => 'auth',
    'POST api/v1/admin/event-announcements/{uuid}/approve' => 'auth',
    'POST api/v1/admin/event-announcements/{uuid}/reject' => 'auth',
    // Blog : lecture publique.
    'GET api/v1/articles' => 'public',
    'GET api/v1/articles/sitemap' => 'public',
    'GET api/v1/articles/{slug}' => 'public',
    'GET api/v1/providers/{slug}/articles' => 'public',
    // Blog : articles de l'auteur (voir BlogAuthorizationTest).
    'GET api/v1/me/articles' => 'auth',
    'POST api/v1/me/articles' => 'auth',
    'GET api/v1/me/articles/quota' => 'auth',
    'GET api/v1/me/articles/{uuid}' => 'auth',
    'PATCH api/v1/me/articles/{uuid}' => 'auth',
    'DELETE api/v1/me/articles/{uuid}' => 'auth',
    'POST api/v1/me/articles/{uuid}/submit' => 'auth',
    'POST api/v1/me/articles/{uuid}/withdraw' => 'auth',
    'DELETE api/v1/me/articles/{uuid}/revision' => 'auth',
    // Blog : modération par les rédacteurs et les admins avec double authentification.
    'GET api/v1/admin/blog/queue' => 'auth',
    'GET api/v1/admin/articles/{uuid}' => 'auth',
    'POST api/v1/admin/articles/{uuid}/approve' => 'auth',
    'POST api/v1/admin/articles/{uuid}/reject' => 'auth',
    'POST api/v1/admin/articles/{uuid}/unpublish' => 'auth',
    'GET api/v1/admin/article-revisions/{uuid}' => 'auth',
    'POST api/v1/admin/article-revisions/{uuid}/approve' => 'auth',
    'POST api/v1/admin/article-revisions/{uuid}/reject' => 'auth',
    // Statut premium : admin avec double authentification (voir PremiumTest).
    'GET api/v1/admin/premium-grants' => 'auth',
    'POST api/v1/admin/premium-grants' => 'auth',
    'POST api/v1/admin/premium-grants/{uuid}/revoke' => 'auth',
    // Compliance : administration des règles, admin avec double authentification
    // (voir RuleAdministrationTest).
    'GET api/v1/admin/compliance/rules' => 'auth',
    'POST api/v1/admin/compliance/rules' => 'auth',
    'GET api/v1/admin/compliance/rules/{uuid}' => 'auth',
    'PATCH api/v1/admin/compliance/rules/{uuid}' => 'auth',
    'DELETE api/v1/admin/compliance/rules/{uuid}' => 'auth',
    'POST api/v1/admin/compliance/rules/{uuid}/revise' => 'auth',
    'POST api/v1/admin/compliance/rules/{uuid}/verify' => 'auth',
    'POST api/v1/admin/compliance/rules/{uuid}/retire' => 'auth',
    'GET api/v1/admin/compliance/coverage' => 'auth',
    // Compliance : suivi administratif des prestataires et entrepreneurs (voir
    // ComplianceAuthorizationTest : 404 pour l'entreprise d'un autre utilisateur).
    'GET api/v1/compliance/countries' => 'auth',
    'GET api/v1/compliance/countries/{country}/questionnaire' => 'auth',
    'GET api/v1/me/compliance' => 'auth',
    'GET api/v1/me/compliance/companies' => 'auth',
    'POST api/v1/me/compliance/companies' => 'auth',
    'GET api/v1/me/compliance/companies/{company}' => 'auth',
    'PATCH api/v1/me/compliance/companies/{company}' => 'auth',
    'DELETE api/v1/me/compliance/companies/{company}' => 'auth',
    'POST api/v1/me/compliance/companies/{company}/activate' => 'auth',
    'GET api/v1/compliance/countries/{country}/journeys' => 'auth',
    'GET api/v1/me/compliance/deadlines' => 'auth',
    'GET api/v1/me/compliance/companies/{company}/deadlines' => 'auth',
    'POST api/v1/me/compliance/companies/{company}/deadlines' => 'auth',
    'GET api/v1/me/compliance/companies/{company}/deadlines/{deadline}' => 'auth',
    'PATCH api/v1/me/compliance/companies/{company}/deadlines/{deadline}' => 'auth',
    'DELETE api/v1/me/compliance/companies/{company}/deadlines/{deadline}' => 'auth',
    'POST api/v1/me/compliance/companies/{company}/deadlines/{deadline}/complete' => 'auth',
    'POST api/v1/me/compliance/companies/{company}/deadlines/{deadline}/skip' => 'auth',
    'POST api/v1/me/compliance/companies/{company}/deadlines/{deadline}/reopen' => 'auth',
    'GET api/v1/me/compliance/companies/{company}/tasks' => 'auth',
    'POST api/v1/me/compliance/companies/{company}/tasks' => 'auth',
    'PATCH api/v1/me/compliance/companies/{company}/tasks/{task}' => 'auth',
    'DELETE api/v1/me/compliance/companies/{company}/tasks/{task}' => 'auth',
    'POST api/v1/me/compliance/companies/{company}/tasks/{task}/complete' => 'auth',
    'POST api/v1/me/compliance/companies/{company}/tasks/{task}/reopen' => 'auth',
    'GET api/v1/me/compliance/companies/{company}/journeys' => 'auth',
    'POST api/v1/me/compliance/companies/{company}/journeys' => 'auth',
    'DELETE api/v1/me/compliance/companies/{company}/journeys/{journey}' => 'auth',
    'GET api/v1/me/compliance/companies/{company}/revenue' => 'auth',
    'GET api/v1/me/compliance/companies/{company}/revenue.csv' => 'auth',
    'PUT api/v1/me/compliance/companies/{company}/revenue/{period}' => 'auth',
    'DELETE api/v1/me/compliance/companies/{company}/revenue/{period}' => 'auth',
    'GET api/v1/me/compliance/companies/{company}/thresholds' => 'auth',
    'GET api/v1/me/compliance/companies/{company}/estimate' => 'auth',
    'GET api/v1/me/compliance/settings' => 'auth',
    'PATCH api/v1/me/compliance/settings' => 'auth',
    'GET api/v1/me/compliance/calendar-feed' => 'auth',
    'POST api/v1/me/compliance/calendar-feed' => 'auth',
    'DELETE api/v1/me/compliance/calendar-feed' => 'auth',
    'GET api/v1/me/compliance/export' => 'auth',
    // Abonnement au calendrier : public, protégé par le jeton secret de l'URL (404 sans jeton
    // valide), voir CalendarFeedTest.
    'GET api/v1/compliance/calendar/{token}.ics' => 'public',
    // Signalements (module Moderation) : ouverts sans compte (DSA art. 16 : tout particulier
    // ou entité) ; le suivi sans compte exige le jeton secret (404 sinon), voir NoticesTest.
    'GET api/v1/moderation/info' => 'public',
    'POST api/v1/moderation/notices' => 'public',
    'POST api/v1/moderation/notices/{uuid}/lookup' => 'public',
    'POST api/v1/moderation/notices/{uuid}/appeal' => 'public',
    'GET api/v1/me/moderation/notices' => 'auth',
    'GET api/v1/me/moderation/notices/{uuid}' => 'auth',
    'GET api/v1/me/moderation/decisions' => 'auth',
    'GET api/v1/me/moderation/decisions/{uuid}' => 'auth',
    'POST api/v1/me/moderation/decisions/{uuid}/appeal' => 'auth',
    'POST api/v1/me/moderation/notices/{uuid}/appeal' => 'auth',
    'GET api/v1/admin/moderation/appeals' => 'auth',
    'GET api/v1/admin/moderation/appeals/{uuid}' => 'auth',
    'POST api/v1/admin/moderation/appeals/{uuid}/decide' => 'auth',
    'GET api/v1/admin/moderation/queue' => 'auth',
    'GET api/v1/admin/moderation/targets/{type}/{ref}' => 'auth',
    'GET api/v1/admin/moderation/decisions/{uuid}' => 'auth',
    'POST api/v1/admin/moderation/targets/{type}/{ref}/decisions' => 'auth',
    'POST api/v1/admin/moderation/decisions/{uuid}/lift' => 'auth',
    'POST api/v1/admin/moderation/notices/{uuid}/referral' => 'auth',
    // Marketplace (lot 3).
    'GET api/v1/me/seller-profile' => 'auth',
    'PUT api/v1/me/seller-profile' => 'auth',
    'GET api/v1/me/offers' => 'auth',
    'POST api/v1/me/offers' => 'auth',
    'GET api/v1/me/offers/{uuid}' => 'auth',
    'PATCH api/v1/me/offers/{uuid}' => 'auth',
    'DELETE api/v1/me/offers/{uuid}' => 'auth',
    'POST api/v1/me/offers/{uuid}/publish' => 'auth',
    'POST api/v1/me/offers/{uuid}/unpublish' => 'auth',
    'GET api/v1/me/quotes' => 'auth',
    'POST api/v1/me/quotes' => 'auth',
    'GET api/v1/me/quotes/{uuid}' => 'auth',
    'PUT api/v1/me/quotes/{uuid}' => 'auth',
    'DELETE api/v1/me/quotes/{uuid}' => 'auth',
    'POST api/v1/me/quotes/{uuid}/send' => 'auth',
    'POST api/v1/me/quotes/{uuid}/revise' => 'auth',
    'POST api/v1/me/quotes/{uuid}/withdraw' => 'auth',
    'POST api/v1/me/quotes/{uuid}/accept' => 'auth',
    'GET api/v1/me/quotes/{uuid}/pdf' => 'auth',
    'POST api/v1/me/quotes/{uuid}/report' => 'auth',
    'POST api/v1/me/quotes/{uuid}/decline' => 'auth',
    'POST api/v1/me/quotes/{uuid}/withdrawal' => 'auth',
    'GET api/v1/me/projects/{uuid}/quotes' => 'auth',
    'GET api/v1/me/projects/{uuid}/quotes/comparison' => 'auth',
    'GET api/v1/me/projects/{uuid}/quotes/comparison.pdf' => 'auth',
    'GET api/v1/offers' => 'public',
    'GET api/v1/offers/{slug}' => 'public',
    'GET api/v1/providers/{slug}/offers' => 'public',
    // Rendez-vous (voir aussi AppointmentsAuthorizationTest).
    'GET api/v1/me/appointments/calendar' => 'auth',
    'PUT api/v1/me/appointments/calendar' => 'auth',
    'GET api/v1/me/appointments/slots' => 'auth',
    'GET api/v1/me/appointment-types' => 'auth',
    'POST api/v1/me/appointment-types' => 'auth',
    'GET api/v1/me/appointment-types/{uuid}' => 'auth',
    'PATCH api/v1/me/appointment-types/{uuid}' => 'auth',
    'DELETE api/v1/me/appointment-types/{uuid}' => 'auth',
    'GET api/v1/me/appointments/availability/rules' => 'auth',
    'PUT api/v1/me/appointments/availability/rules' => 'auth',
    'GET api/v1/me/appointments/availability/exceptions' => 'auth',
    'POST api/v1/me/appointments/availability/exceptions' => 'auth',
    'DELETE api/v1/me/appointments/availability/exceptions/{uuid}' => 'auth',
    'GET api/v1/me/match-requests/{uuid}/booking' => 'auth',
    'GET api/v1/me/match-requests/{uuid}/appointments' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/appointments' => 'auth',
    'GET api/v1/me/appointments' => 'auth',
    'GET api/v1/me/appointments/{uuid}' => 'auth',
    'GET api/v1/me/appointments/{uuid}/calendar.ics' => 'auth',
    'POST api/v1/me/appointments/{uuid}/confirm' => 'auth',
    'POST api/v1/me/appointments/{uuid}/decline' => 'auth',
    'POST api/v1/me/appointments/{uuid}/cancel' => 'auth',
    'POST api/v1/me/appointments/{uuid}/reschedule' => 'auth',
    'POST api/v1/me/appointments/{uuid}/accept-change' => 'auth',
    'POST api/v1/me/appointments/{uuid}/decline-change' => 'auth',
    'POST api/v1/me/appointments/{uuid}/notes/{noteUuid}/report' => 'auth',
    'GET api/v1/providers/{slug}/booking' => 'public',
    'POST api/v1/providers/{slug}/appointments' => 'auth',
    // Agenda unifié et abonnement unique (noyau).
    'GET api/v1/me/agenda' => 'auth',
    'GET api/v1/me/agenda/feed' => 'auth',
    'POST api/v1/me/agenda/feed' => 'auth',
    'DELETE api/v1/me/agenda/feed' => 'auth',
    'GET api/v1/agenda/{token}.ics' => 'public',

    // Suivi des coûts de l'IA (lot 5, admins).
    'GET api/v1/admin/ai/usage' => 'auth',

    // Parcours d'accueil (lot 5).
    'GET api/v1/me/onboarding' => 'auth',
    'POST api/v1/me/onboarding/dismiss' => 'auth',
    'POST api/v1/me/onboarding/steps/{key}/skip' => 'auth',

    // Workspaces (lot 5) : privés, aucune route publique.
    'GET api/v1/workspace-templates' => 'auth',
    'GET api/v1/me/copilot' => 'auth',
    'GET api/v1/me/workspaces/{uuid}/copilot/preview' => 'auth',
    'GET api/v1/me/workspaces/{uuid}/copilot/suggestions' => 'auth',
    'GET api/v1/me/workspaces/{uuid}/copilot/suggestions/{suggestion}' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/copilot/roadmap' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/copilot/specification' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/copilot/suggestions/{suggestion}/apply' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/copilot/suggestions/{suggestion}/dismiss' => 'auth',
    'GET api/v1/me/workspaces' => 'auth',
    'POST api/v1/me/workspaces' => 'auth',
    'GET api/v1/me/workspaces/{uuid}' => 'auth',
    'PATCH api/v1/me/workspaces/{uuid}' => 'auth',
    'DELETE api/v1/me/workspaces/{uuid}' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/archive' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/unarchive' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/activate' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/linked-project' => 'auth',
    'DELETE api/v1/me/workspaces/{uuid}/linked-project' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/directory-draft' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/goals' => 'auth',
    'PUT api/v1/me/workspaces/{uuid}/goals/order' => 'auth',
    'PATCH api/v1/me/workspaces/{uuid}/goals/{goal}' => 'auth',
    'DELETE api/v1/me/workspaces/{uuid}/goals/{goal}' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/steps' => 'auth',
    'PUT api/v1/me/workspaces/{uuid}/steps/order' => 'auth',
    'PATCH api/v1/me/workspaces/{uuid}/steps/{step}' => 'auth',
    'DELETE api/v1/me/workspaces/{uuid}/steps/{step}' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/steps/{step}/tasks' => 'auth',
    'PUT api/v1/me/workspaces/{uuid}/steps/{step}/tasks/order' => 'auth',
    'PATCH api/v1/me/workspaces/{uuid}/tasks/{task}' => 'auth',
    'DELETE api/v1/me/workspaces/{uuid}/tasks/{task}' => 'auth',
    'GET api/v1/me/workspaces/{uuid}/specifications' => 'auth',
    'POST api/v1/me/workspaces/{uuid}/specifications' => 'auth',
    'GET api/v1/me/workspaces/{uuid}/specifications/{specification}' => 'auth',
    'PUT api/v1/me/workspaces/{uuid}/specifications/{specification}' => 'auth',
    'DELETE api/v1/me/workspaces/{uuid}/specifications/{specification}' => 'auth',
];

/**
 * Remplace les paramètres d'une route par des valeurs valides syntaxiquement.
 */
function concreteUri(string $uri): string
{
    return '/'.strtr($uri, [
        '{uuid}' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
        '{company}' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
        '{deadline}' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        '{key}' => 'publish_project',
        '{goal}' => 'e3b0c442-98fc-4c14-9afb-f4c8996fb924',
        '{step}' => 'd4735e3a-265e-46ef-8b3a-8e2b5d6f7a10',
        '{specification}' => '4e07408c-5c8b-4b2a-9f1e-3d2c1b0a9f8e',
        '{suggestion}' => '6b86b273-ff34-4fce-9d2b-6e5f4a3b2c1d',
        '{task}' => '16fd2706-8baf-433b-82eb-8c7fada847da',
        '{journey}' => 'a8098c1a-f86e-11da-bd1a-00112444be1e',
        '{period}' => '2026-10',
        '{token}' => str_repeat('a', 56),
        '{country}' => 'FR',
        '{messageUuid}' => '1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed',
        '{noteUuid}' => '5a2b6c0e-1d2f-4c3b-9e8f-7a6b5c4d3e2f',
        '{registrationUuid}' => '6ec0bd7f-11c0-43da-975e-2a8ad9ebae0b',
        '{slug}' => 'un-prestataire',
        '{id}' => '1',
        '{hash}' => str_repeat('a', 40),
        '{type}' => 'terms',
        '{ref}' => 'un-contenu',
    ]);
}

it('classe chaque route de l\'API comme publique ou authentifiée', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
        ->flatMap(fn ($route): array => array_map(
            fn (string $method): string => "{$method} {$route->uri()}",
            array_diff($route->methods(), ['HEAD']),
        ))
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe(collect(array_keys(API_ROUTES))->sort()->values()->all());
});

it('refuse chaque route authentifiée à un visiteur anonyme (401)', function (string $route): void {
    [$method, $uri] = explode(' ', $route, 2);

    $this->json($method, concreteUri($uri))->assertUnauthorized();
})->with(array_keys(array_filter(API_ROUTES, fn (string $access): bool => $access === 'auth')));

it('ouvre chaque route publique à un visiteur anonyme', function (string $route): void {
    [$method, $uri] = explode(' ', $route, 2);

    expect($this->json($method, concreteUri($uri))->status())->not->toBeIn([401, 403]);
})->with(array_keys(array_filter(API_ROUTES, fn (string $access): bool => $access === 'public')));

describe('BFLA : fonctions réservées à un rôle', function (): void {
    it('cache les projets derrière un 404 pour les rôles qui ne gèrent pas de projets', function (string $role): void {
        $project = Project::factory()->published()->create();
        $user = projectOwner($role);

        $this->actingAs($user)->getJson("/api/v1/me/projects/{$project->uuid}")->assertNotFound();
        $this->actingAs($user)->patchJson("/api/v1/me/projects/{$project->uuid}", ['title' => 'Piraté par un autre rôle'])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/v1/me/projects/{$project->uuid}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/v1/me/projects/{$project->uuid}/publish")->assertNotFound();
        $this->actingAs($user)->postJson("/api/v1/me/projects/{$project->uuid}/close")->assertNotFound();

        expect($project->fresh()->status)->toBe(ProjectStatus::Published)
            ->and($project->fresh()->title)->not->toBe('Piraté par un autre rôle');
    })->with(['prestataire', 'redacteur', 'admin']);

    it('réserve le profil prestataire au rôle prestataire, y compris pour l\'admin', function (string $role): void {
        $user = projectOwner($role);

        $this->actingAs($user)->getJson('/api/v1/me/provider-profile')->assertForbidden();
        $this->actingAs($user)->putJson('/api/v1/me/provider-profile', ['display_name' => 'Faux prestataire'])->assertForbidden();

        expect(ProviderProfile::query()->count())->toBe(0);
    })->with(['particulier', 'entrepreneur', 'redacteur', 'admin']);
});

describe('assignation de propriétés non autorisées', function (): void {
    it('ignore le propriétaire, le statut, les dates et l\'uuid envoyés à la création d\'un projet', function (): void {
        serviceCategory('site-vitrine');
        $owner = projectOwner('particulier');
        $victim = projectOwner('particulier');

        $uuid = $this->actingAs($owner)->postJson('/api/v1/me/projects', projectPayload([
            'user_id' => $victim->id,
            'status' => 'published',
            'published_at' => '2026-01-01T00:00:00Z',
            'expires_at' => '2030-01-01T00:00:00Z',
            'uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            'service_category_id' => 999,
        ]))->assertCreated()->json('data.uuid');

        $project = Project::query()->where('uuid', $uuid)->sole();
        expect($uuid)->not->toBe('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')
            ->and($project->user_id)->toBe($owner->id)
            ->and($project->status)->toBe(ProjectStatus::Draft)
            ->and($project->published_at)->toBeNull()
            ->and($project->expires_at)->toBeNull();
    });

    it('ignore le propriétaire, la date de publication et le logo envoyés dans le profil prestataire', function (): void {
        $provider = projectOwner('prestataire');
        $victim = projectOwner('prestataire');

        $this->actingAs($provider)->putJson('/api/v1/me/provider-profile', [
            'display_name' => 'Mon studio',
            'user_id' => $victim->id,
            'published_at' => '2026-01-01T00:00:00Z',
            'logo_media_id' => 1,
            'id' => 999,
        ])->assertCreated();

        $profile = ProviderProfile::query()->sole();
        expect($profile->user_id)->toBe($provider->id)
            ->and($profile->published_at)->toBeNull()
            ->and($profile->logo_media_id)->toBeNull()
            ->and($profile->id)->not->toBe(999);
    });

    it('ignore la vérification de l\'email, les secrets 2FA et l\'avatar interne envoyés dans PATCH /me', function (): void {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->patchJson('/api/v1/me', [
            'name' => 'Camille',
            'email_verified_at' => '2026-01-01 00:00:00',
            'two_factor_secret' => 'SECRET',
            'two_factor_confirmed_at' => '2026-01-01 00:00:00',
            'avatar_media_id' => 1,
            'remember_token' => 'jeton',
        ])->assertOk();

        $user->refresh();
        expect($user->name)->toBe('Camille')
            ->and($user->hasVerifiedEmail())->toBeFalse()
            ->and($user->two_factor_secret)->toBeNull()
            ->and($user->two_factor_confirmed_at)->toBeNull()
            ->and($user->avatar_media_id)->toBeNull()
            ->and($user->remember_token)->not->toBe('jeton');
    });

    it('n\'attribue jamais le rôle demandé dans l\'inscription au-delà du type de compte', function (): void {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', registrationPayload(['roles' => ['admin'], 'role' => 'admin']))
            ->assertStatus(202);

        expect(User::query()->sole()->getRoleNames()->all())->toBe(['particulier']);
    });
});
