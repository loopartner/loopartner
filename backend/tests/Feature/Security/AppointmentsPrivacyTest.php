<?php

use App\Modules\Appointments\Enums\HoldKind;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Models\SlotHold;
use App\Modules\Appointments\Support\SlotGenerator;
use App\Modules\Directory\Actions\AcceptMatchRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Rendez-vous : confidentialité (journal security, réponses), limites de débit (429) et double
| réservation impossible même quand deux demandes passent le contrôle en même temps.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
});

const PRIVATE_NOTE = 'Mon digicode est 4821, troisième étage.';
const PRIVATE_PHONE = '+33 6 98 76 54 32';

it('n\'écrit ni note, ni lieu, ni nom, ni email, ni jeton dans le journal security', function (): void {
    [$client, $provider, $matchRequest] = appointmentRelation();
    weeklyAvailability($provider);
    $type = appointmentTypeOf($provider, ['location_mode' => 'phone', 'location_details' => null]);

    $uuid = $this->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-23T08:00:00Z', 'note' => PRIVATE_NOTE, 'phone' => PRIVATE_PHONE])->assertCreated()->json('data.uuid');
    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertOk();
    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-24T08:00:00Z', 'message' => 'Message de report '.PRIVATE_NOTE])->assertOk();
    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/decline-change")->assertOk();
    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'other', 'message' => 'Annulation '.PRIVATE_NOTE])->assertOk();
    $url = (string) $this->actingAs($client)->postJson('/api/v1/me/agenda/feed')->json('data.url');

    $log = (string) json_encode(array_map(fn ($record): array => $record->context, securityLog()));

    expect(securityLog('appointments.requested'))->toHaveCount(1)
        ->and(securityLog('appointments.cancelled'))->toHaveCount(1)
        ->and($log)->not->toContain('4821')
        ->and($log)->not->toContain('98 76')
        ->and($log)->not->toContain($client->name)
        ->and($log)->not->toContain($client->email)
        ->and($log)->not->toContain($provider->email)
        ->and($log)->not->toContain(substr($url, -48, 40))
        ->and($log)->not->toContain('Premier échange');
});

it('chiffre en base le lieu et les notes', function (): void {
    [$client, $provider, $matchRequest] = appointmentRelation();
    weeklyAvailability($provider);
    $type = appointmentTypeOf($provider, ['location_mode' => 'phone', 'location_details' => null]);
    $this->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-23T08:00:00Z', 'note' => PRIVATE_NOTE, 'phone' => PRIVATE_PHONE])->assertCreated();

    $raw = (string) json_encode([DB::table('appointments')->get(), DB::table('appointment_notes')->get(), DB::table('appointment_types')->get()]);

    expect($raw)->not->toContain('4821')
        ->and($raw)->not->toContain('98 76')
        ->and($raw)->not->toContain('visio.exemple.fr');
});

it('n\'expose aucun identifiant interne ni email dans les réponses', function (): void {
    [$client, $provider, $matchRequest] = appointmentRelation();
    weeklyAvailability($provider);
    $type = appointmentTypeOf($provider, ['auto_confirm' => true]);

    $created = $this->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-23T08:00:00Z', 'note' => PRIVATE_NOTE])->assertCreated()->json();
    $listed = $this->actingAs($provider)->getJson('/api/v1/me/appointments')->assertOk()->json();
    $agenda = $this->actingAs($provider)->getJson('/api/v1/me/agenda')->assertOk()->json();
    $calendar = $this->actingAs($provider)->getJson('/api/v1/me/appointments/calendar')->assertOk()->json();

    foreach ([$created, $listed, $agenda, $calendar] as $payload) {
        expectNoPrivateFields($payload);
        expect(json_encode($payload))->not->toContain($client->email)->not->toContain($provider->email);
    }
});

describe('limites de débit', function (): void {
    it('limite les réservations, propositions et reports (429)', function (): void {
        config(['security.rate_limits.appointment_bookings.per_hour' => 1]);
        [$client, $provider, $matchRequest] = appointmentRelation();
        weeklyAvailability($provider);
        $type = appointmentTypeOf($provider);

        $this->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-23T08:00:00Z'])->assertCreated();
        $this->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-23T08:30:00Z'])->assertTooManyRequests();
    });

    it('limite les réponses (429)', function (): void {
        config(['security.rate_limits.appointment_decisions.per_hour' => 1]);
        $user = projectOwner('particulier');
        $uuid = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

        $this->actingAs($user)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertNotFound();
        $this->actingAs($user)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertTooManyRequests();
    });

    it('limite les réglages du calendrier (429)', function (): void {
        config(['security.rate_limits.appointment_settings_writes.per_hour' => 1]);
        $provider = browsingProvider();

        $this->actingAs($provider)->putJson('/api/v1/me/appointments/availability/rules', ['rules' => []])->assertOk();
        $this->actingAs($provider)->putJson('/api/v1/me/appointments/availability/rules', ['rules' => []])->assertTooManyRequests();
    });

    it('limite les créneaux de la page publique par IP et ceux du prestataire par compte (429)', function (): void {
        config(['security.rate_limits.appointment_slots.public_per_minute_per_ip' => 1, 'security.rate_limits.appointment_slots.per_minute' => 1]);
        $provider = appointmentProvider();

        $this->getJson('/api/v1/providers/'.providerSlug($provider).'/booking')->assertNotFound();
        $this->getJson('/api/v1/providers/'.providerSlug($provider).'/booking')->assertTooManyRequests();

        $type = appointmentTypeOf($provider);
        $this->actingAs($provider)->getJson("/api/v1/me/appointments/slots?type={$type->uuid}")->assertOk();
        $this->actingAs($provider)->getJson("/api/v1/me/appointments/slots?type={$type->uuid}")->assertTooManyRequests();
    });

    it('limite les fichiers .ics, l\'agenda et la gestion de l\'abonnement (429)', function (string $limit, string $method, string $uri, int $status): void {
        config(["security.rate_limits.{$limit}" => 1]);
        $user = projectOwner('particulier');

        expect($this->actingAs($user)->json($method, $uri)->status())->toBe($status);
        $this->actingAs($user)->json($method, $uri)->assertTooManyRequests();
    })->with([
        'ics' => ['appointment_ics.per_hour', 'GET', '/api/v1/me/appointments/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/calendar.ics', 404],
        'agenda' => ['agenda.per_minute', 'GET', '/api/v1/me/agenda', 200],
        'abonnement' => ['agenda_feed_manage.per_day', 'POST', '/api/v1/me/agenda/feed', 201],
    ]);
});

describe('double réservation simultanée', function (): void {
    it('refuse la seconde de deux réservations qui ont toutes deux vu le créneau libre', function (): void {
        [$client, $provider, $matchRequest] = appointmentRelation();
        weeklyAvailability($provider);
        $type = appointmentTypeOf($provider, ['auto_confirm' => true]);
        $other = projectOwner('particulier');
        $otherRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf($other)));

        $this->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-23T08:00:00Z'])->assertCreated();

        // La seconde demande a lu les créneaux avant que la première n'enregistre le sien : son
        // contrôle la croit libre. L'index unique slot_key la refuse quand même.
        $this->app->instance(SlotGenerator::class, new class extends SlotGenerator
        {
            public function isFree(Calendar $calendar, int $durationMinutes, CarbonImmutable $startsAt, int $horizonDays, ?int $ignoreAppointmentId = null): bool
            {
                return true;
            }
        });

        $this->actingAs($other)->postJson("/api/v1/me/match-requests/{$otherRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-23T08:00:00Z'])
            ->assertConflict()
            ->assertJsonPath('code', 'slot_unavailable');

        expect(Appointment::query()->count())->toBe(1)
            ->and(SlotHold::query()->where('kind', HoldKind::Current->value)->count())->toBe(1)
            ->and(securityLog('appointments.slot_conflict'))->toHaveCount(1);
    });

    it('verrouille la ligne du calendrier du prestataire pendant la réservation (MySQL, MariaDB)', function (): void {
        [$client, $provider, $matchRequest] = appointmentRelation();
        weeklyAvailability($provider);
        $type = appointmentTypeOf($provider);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-23T08:00:00Z'])->assertCreated();

        $locks = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'appointment_calendars') && str_contains($sql, 'for update'));
        expect($locks)->not->toBeEmpty();
    })->skip(fn (): bool => DB::connection()->getDriverName() === 'sqlite', 'SQLite ignore FOR UPDATE : vérifié sur MySQL et MariaDB en intégration continue');
});
