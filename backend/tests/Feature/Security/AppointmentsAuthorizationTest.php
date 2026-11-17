<?php

use App\Models\User;
use App\Modules\Appointments\Models\AppointmentNote;
use App\Modules\Appointments\Models\AvailabilityException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| Rendez-vous : autorisation de CHAQUE route du module, lue dans le routeur, contre chaque rôle
| et un autre utilisateur (OWASP API1 BOLA, API5 BFLA). Une nouvelle route du module est testée
| sans rien ajouter ici.
|
| - Route qui vise un rendez-vous ou une demande de mise en relation d'autrui : 404 pour tout
|   autre utilisateur, admins compris, et aucune donnée modifiée.
| - Route qui vise un type ou une exception d'un autre prestataire : 404 pour un prestataire,
|   403 pour les autres rôles (calendrier réservé aux prestataires).
| - Autre route du calendrier : 403 pour les rôles qui ne sont pas prestataires.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
});

/**
 * Données d'une victime : relation acceptée, rendez-vous confirmé avec une note, type et
 * exception de son prestataire.
 *
 * @return array<string, string>
 */
function appointmentVictim(): array
{
    [$client, $provider, $matchRequest] = appointmentRelation();
    weeklyAvailability($provider);
    $type = appointmentTypeOf($provider, ['auto_confirm' => true]);
    $uuid = test()->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-23T08:00:00Z', 'note' => 'Note privée de la victime.'])->assertCreated()->json('data.uuid');
    $exception = AvailabilityException::query()->forceCreate(['uuid' => (string) Str::uuid(), 'calendar_id' => appointmentCalendarOf($provider)->id, 'date' => '2027-04-01', 'kind' => 'closed']);

    return [
        'appointment' => $uuid,
        'match_request' => $matchRequest->uuid,
        'type' => $type->uuid,
        'exception' => $exception->uuid,
        'note' => AppointmentNote::query()->sole()->uuid,
    ];
}

/**
 * Routes du module (nom commençant par "appointments."), sauf la page publique (testée à
 * part) : [méthode, uri].
 *
 * @return list<array{0: string, 1: string}>
 */
function appointmentRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'appointments.') && ! str_starts_with((string) $route->getName(), 'appointments.public.'))
        ->flatMap(fn ($route): array => array_map(fn (string $method): array => [$method, $route->uri()], array_values(array_diff($route->methods(), ['HEAD']))))
        ->values()
        ->all();
}

function appointmentsSnapshot(): string
{
    return (string) json_encode([
        DB::table('appointments')->orderBy('id')->get(['id', 'status', 'starts_at', 'version', 'updated_at']),
        DB::table('appointment_types')->orderBy('id')->get(['id', 'title', 'is_active', 'updated_at']),
        DB::table('appointment_availability_exceptions')->orderBy('id')->get(['id']),
        DB::table('appointment_slot_holds')->orderBy('id')->get(['id', 'slot_key']),
        DB::table('appointment_notes')->orderBy('id')->get(['id', 'moderation_restricted_at']),
    ]);
}

function appointmentIntruder(string $role): User
{
    return match ($role) {
        'admin' => moderator(),
        'prestataire' => appointmentProvider(),
        default => projectOwner($role),
    };
}

it('teste chaque route du module', function (): void {
    expect(count(appointmentRoutes()))->toBeGreaterThanOrEqual(25);
});

it('cache le rendez-vous, la relation, le type et l\'exception d\'autrui', function (string $role): void {
    $victim = appointmentVictim();
    $intruder = appointmentIntruder($role);
    $before = appointmentsSnapshot();

    foreach (appointmentRoutes() as [$method, $uri]) {
        if (! str_contains($uri, '{uuid}')) {
            continue;
        }

        $ref = match (true) {
            str_contains($uri, 'match-requests') => $victim['match_request'],
            str_contains($uri, 'appointment-types') => $victim['type'],
            str_contains($uri, 'availability/exceptions') => $victim['exception'],
            default => $victim['appointment'],
        };
        $path = '/'.strtr($uri, ['{uuid}' => $ref, '{noteUuid}' => $victim['note']]);
        $calendarRoute = str_contains($uri, 'appointment-types') || str_contains($uri, 'availability/exceptions');
        $expected = $calendarRoute && $role !== 'prestataire' ? 403 : 404;

        expect($this->actingAs($intruder)->json($method, $path, ['title' => 'Piraté', 'reason' => 'other', 'starts_at' => '2027-03-24T08:00:00Z'])->status())
            ->toBe($expected, "{$method} {$uri}");
    }

    expect(appointmentsSnapshot())->toBe($before);
})->with(['prestataire', 'particulier', 'entrepreneur', 'redacteur', 'admin']);

it('réserve le calendrier, les types et les disponibilités aux prestataires', function (string $role): void {
    $user = appointmentIntruder($role);

    foreach (appointmentRoutes() as [$method, $uri]) {
        $calendarRoute = str_starts_with($uri, 'api/v1/me/appointments/calendar')
            || str_starts_with($uri, 'api/v1/me/appointments/slots')
            || str_starts_with($uri, 'api/v1/me/appointments/availability')
            || str_starts_with($uri, 'api/v1/me/appointment-types');

        if (! $calendarRoute || str_contains($uri, '{uuid}')) {
            continue;
        }

        expect($this->actingAs($user)->json($method, '/'.$uri, [])->status())->toBe(403, "{$method} {$uri}");
    }
})->with(['particulier', 'entrepreneur', 'redacteur', 'admin']);
