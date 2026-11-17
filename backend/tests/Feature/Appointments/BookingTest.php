<?php

use App\Models\User;
use App\Modules\Appointments\Actions\ExpireAppointments;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\HoldKind;
use App\Modules\Appointments\Exceptions\SlotUnavailable;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentNote;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\SlotHold;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\SlotLedger;
use App\Modules\Directory\Actions\AcceptMatchRequest;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\UserBlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
| Réservation dans une mise en relation acceptée (décisions 52, 53 et 55) : réservation par le
| client, proposition par le prestataire, confirmation, refus, annulation, expiration, et
| double réservation impossible.
|
| Horloge : lundi 22 mars 2027, 08:00 à Paris ; plages de 09:00 à 12:00 tous les jours ;
| premier créneau : 09:00 (08:00 UTC).
*/

const FIRST_SLOT = '2027-03-22T08:00:00Z';
const SECOND_SLOT = '2027-03-22T08:30:00Z';

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
});

/**
 * @return array{0: User, 1: User, 2: MatchRequest, 3: AppointmentType}
 */
function bookableRelation(array $type = [], array $calendar = [], string $clientRole = 'particulier'): array
{
    [$client, $provider, $matchRequest] = appointmentRelation($clientRole, $calendar);
    weeklyAvailability($provider);

    return [$client, $provider, $matchRequest, appointmentTypeOf($provider, $type)];
}

function bookIn(MatchRequest $matchRequest, User $actor, string $type, string $startsAt = FIRST_SLOT, array $extra = []): TestResponse
{
    return test()->actingAs($actor)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type, 'starts_at' => $startsAt, ...$extra]);
}

describe('réservation par le client', function (): void {
    it('crée une demande à confirmer, retient le créneau et prévient le prestataire', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation();

        $uuid = bookIn($matchRequest, $client, $type->uuid, FIRST_SLOT, ['note' => 'Je voudrais parler de la refonte de mon site.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.viewer_role', 'client')
            ->assertJsonPath('data.awaiting_me', false)
            ->assertJsonPath('data.starts_at', FIRST_SLOT)
            ->assertJsonPath('data.local.starts_at', '2027-03-22T09:00')
            ->assertJsonPath('data.location', null)
            ->assertJsonPath('data.counterpart.type', 'provider')
            ->assertJsonPath('data.match_request', $matchRequest->uuid)
            ->json('data.uuid');

        expect(SlotHold::query()->sole()->kind)->toBe(HoldKind::Current);
        Notification::assertSentTo($provider, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::RequestReceived);

        $this->actingAs($provider)->getJson("/api/v1/me/appointments/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.awaiting_me', true)
            ->assertJsonPath('data.counterpart.type', 'client')
            ->assertJsonPath('data.counterpart.account_type', 'particulier')
            ->assertJsonPath('data.counterpart.name', $client->name)
            ->assertJsonPath('data.notes.0.body', 'Je voudrais parler de la refonte de mon site.')
            ->assertJsonPath('data.notes.0.mine', false);
    });

    it('confirme tout de suite un type en confirmation automatique, avec un .ics sans lieu', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation(['auto_confirm' => true]);

        $uuid = bookIn($matchRequest, $client, $type->uuid)
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.location.video_url', 'https://visio.exemple.fr/salle-test')
            ->json('data.uuid');

        Notification::assertSentTo([$client, $provider], AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::Confirmed);

        $client->forceFill(['timezone' => 'Europe/Paris'])->save();
        $mail = (new AppointmentNotification(AppointmentNotice::Confirmed, Appointment::query()->where('uuid', $uuid)->sole()))->toMail($client);
        $ics = (string) $mail->rawAttachments[0]['data'];
        $text = implode("\n", $mail->introLines);

        expect($text)->toContain('Premier échange')
            ->and($text)->toContain('lundi 22 mars 2027 à 09:00')
            ->and($text)->not->toContain('visio.exemple.fr')
            ->and($ics)->toContain('SUMMARY:Rendez-vous : Premier échange')
            ->and($ics)->toContain('DTSTART:20270322T080000Z')
            ->and($ics)->not->toContain('visio')
            ->and($ics)->not->toContain('LOCATION');
    });

    it('exige l\'adresse ou le téléphone quand le client les donne, et les chiffre', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation(['location_mode' => 'phone', 'location_details' => null, 'auto_confirm' => true]);

        bookIn($matchRequest, $client, $type->uuid)->assertUnprocessable()->assertJsonValidationErrors('phone');
        bookIn($matchRequest, $client, $type->uuid, FIRST_SLOT, ['phone' => 'appelez-moi'])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $uuid = bookIn($matchRequest, $client, $type->uuid, FIRST_SLOT, ['phone' => '+33 6 12 34 56 78'])->assertCreated()->json('data.uuid');

        $this->actingAs($provider)->getJson("/api/v1/me/appointments/{$uuid}")->assertJsonPath('data.location.phone', '+33 6 12 34 56 78');
        expect((string) DB::table('appointments')->value('location'))->not->toContain('12 34 56');
    });

    it('refuse un créneau qui n\'est pas libre', function (string $startsAt): void {
        [$client, , $matchRequest, $type] = bookableRelation();

        bookIn($matchRequest, $client, $type->uuid, $startsAt)->assertConflict()->assertJsonPath('code', 'slot_unavailable');
        expect(Appointment::query()->count())->toBe(0);
    })->with([
        'hors plage' => ['2027-03-22T13:00:00Z'],
        'hors pas' => ['2027-03-22T08:10:00Z'],
        'passé' => ['2027-03-21T08:00:00Z'],
        'au-delà de l\'horizon' => ['2027-04-30T08:00:00Z'],
    ]);

    it('refuse un type inconnu, inactif ou d\'un autre prestataire', function (): void {
        [$client, , $matchRequest, $type] = bookableRelation(['is_active' => false]);

        bookIn($matchRequest, $client, $type->uuid)->assertUnprocessable()->assertJsonValidationErrors('type');
        bookIn($matchRequest, $client, appointmentTypeOf(appointmentProvider())->uuid)->assertUnprocessable()->assertJsonValidationErrors('type');
    });

    it('ignore le statut et les dates envoyés par le client', function (): void {
        [$client, , $matchRequest, $type] = bookableRelation();

        bookIn($matchRequest, $client, $type->uuid, FIRST_SLOT, ['status' => 'confirmed', 'provider_id' => 1, 'expires_at' => '2030-01-01T00:00:00Z'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested');
    });

    it('refuse une note trop longue ou en HTML', function (array $extra, string $field): void {
        [$client, , $matchRequest, $type] = bookableRelation();

        bookIn($matchRequest, $client, $type->uuid, FIRST_SLOT, $extra)->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'HTML' => [['note' => '<script>alert(1)</script>'], 'note'],
        'trop longue' => [['note' => str_repeat('a', 501)], 'note'],
        'date mal formée' => [['starts_at' => '22/03/2027 09:00'], 'starts_at'],
    ]);
});

describe('aucune double réservation', function (): void {
    it('refuse le même créneau à un second client du même prestataire', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation();
        $other = projectOwner('entrepreneur');
        $otherRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf($other)));

        bookIn($matchRequest, $client, $type->uuid)->assertCreated();
        bookIn($otherRequest, $other, $type->uuid)->assertConflict()->assertJsonPath('code', 'slot_unavailable');
        bookIn($otherRequest, $other, $type->uuid, SECOND_SLOT)->assertCreated();

        expect(SlotHold::query()->count())->toBe(2);
    });

    it('refuse un créneau qui chevauche un rendez-vous, marge comprise', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation(['duration_minutes' => 60], ['buffer_minutes' => 15]);
        bookIn($matchRequest, $client, $type->uuid)->assertCreated();

        $slots = collect($this->actingAs($provider)->getJson("/api/v1/me/appointments/slots?type={$type->uuid}&from=2027-03-22&to=2027-03-22")->json('data'))->pluck('local.starts_at')->all();

        // 09:00-10:00 retenu, marge de 15 minutes : prochain départ possible à 10:30 (pas de 30 minutes).
        expect($slots)->toBe(['2027-03-22T10:30', '2027-03-22T11:00']);
    });

    it('refuse en base un second créneau retenu au même début, même sans le contrôle préalable', function (): void {
        [$client, , $matchRequest, $type] = bookableRelation();
        $uuid = bookIn($matchRequest, $client, $type->uuid)->json('data.uuid');
        $appointment = Appointment::query()->where('uuid', $uuid)->sole();
        $other = $appointment->replicate(['uuid'])->forceFill(['uuid' => (string) Str::uuid()]);
        $other->save();

        expect(fn () => app(SlotLedger::class)->hold($appointment->calendar, $other, HoldKind::Current, CarbonImmutable::parse(FIRST_SLOT), CarbonImmutable::parse(FIRST_SLOT)->addMinutes(30)))
            ->toThrow(SlotUnavailable::class);
        expect(securityLog('appointments.slot_conflict'))->toHaveCount(1)
            ->and(SlotHold::query()->count())->toBe(1);
    });
});

describe('créneaux libérés', function (): void {
    it('libère le créneau à l\'annulation, au refus et à l\'expiration, index unique compris', function (string $ending): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation();
        $uuid = bookIn($matchRequest, $client, $type->uuid)->assertCreated()->json('data.uuid');

        match ($ending) {
            'annulation' => $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'no_longer_needed'])->assertOk(),
            'refus' => $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/decline")->assertOk(),
            'expiration' => (function (): void {
                $this->travelTo(CarbonImmutable::parse('2027-03-22 08:00:00'));
                app(ExpireAppointments::class)->handle();
            })(),
        };

        expect(SlotHold::query()->count())->toBe(0);

        $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
        $second = projectOwner('particulier');
        $secondRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf($second)));
        bookIn($secondRequest, $second, $type->uuid)->assertCreated();
        expect(SlotHold::query()->sole()->slot_key)->toEndWith('2027-03-22T08:00');
    })->with(['annulation', 'refus', 'expiration']);
});

describe('proposition par le prestataire', function (): void {
    it('propose un créneau que le client confirme, puis prévient les deux parties', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation(['location_mode' => 'client_place', 'location_details' => null]);

        $uuid = bookIn($matchRequest, $provider, $type->uuid)->assertCreated()->assertJsonPath('data.status', 'proposed')->json('data.uuid');
        Notification::assertSentTo($client, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::ProposalReceived);

        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertForbidden();
        $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertUnprocessable()->assertJsonValidationErrors('address');

        $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/confirm", ['address' => '5 place du Marché, 69002 Lyon'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.location.address', '5 place du Marché, 69002 Lyon');

        Notification::assertSentTo([$client, $provider], AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::Confirmed);
        $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertConflict();
    });

    it('limite les propositions en attente par relation et au total', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation();

        foreach (['08:00', '08:30', '09:00'] as $time) {
            bookIn($matchRequest, $provider, $type->uuid, "2027-03-22T{$time}:00Z")->assertCreated();
        }

        bookIn($matchRequest, $provider, $type->uuid, '2027-03-22T09:30:00Z')
            ->assertConflict()
            ->assertJsonPath('message', 'Vous avez déjà 3 propositions de rendez-vous en attente dans cette relation.');

        config(['appointments.provider.max_pending_proposals' => 3]);
        $other = projectOwner('particulier');
        $otherRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf($other)));

        bookIn($otherRequest, $provider, $type->uuid, '2027-03-22T09:30:00Z')
            ->assertConflict()
            ->assertJsonPath('message', 'Vous avez déjà 3 propositions de rendez-vous en attente de réponse.');
    });
});

describe('limites', function (): void {
    it('limite les demandes en attente et les rendez-vous à venir du client', function (): void {
        [$client, , $matchRequest, $type] = bookableRelation();
        config(['appointments.client.max_pending_requests' => 1]);

        bookIn($matchRequest, $client, $type->uuid)->assertCreated();
        bookIn($matchRequest, $client, $type->uuid, SECOND_SLOT)
            ->assertConflict()
            ->assertJsonPath('message', 'Vous avez déjà 1 demandes de rendez-vous en attente de réponse.');

        config(['appointments.client.max_pending_requests' => 5, 'appointments.client.max_upcoming_per_relation' => 1]);
        bookIn($matchRequest, $client, $type->uuid, SECOND_SLOT)->assertConflict();
    });

    it('cache au client le plan du prestataire complet, et propose le Premium au prestataire', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation();
        config(['appointments.plans.free.upcoming_appointments' => 1]);

        bookIn($matchRequest, $client, $type->uuid)->assertCreated();
        bookIn($matchRequest, $client, $type->uuid, SECOND_SLOT)
            ->assertConflict()
            ->assertJsonPath('message', 'Ce prestataire ne peut plus recevoir de rendez-vous pour le moment.');
        bookIn($matchRequest, $provider, $type->uuid, SECOND_SLOT)
            ->assertForbidden()
            ->assertJsonPath('feature', 'upcoming_appointments');
    });
});

describe('conditions de la relation', function (): void {
    it('cache la relation à tout autre utilisateur, même avec un corps invalide', function (string $role): void {
        [, , $matchRequest, $type] = bookableRelation();
        $intruder = $role === 'admin' ? moderator() : projectOwner($role);

        $this->actingAs($intruder)->getJson("/api/v1/me/match-requests/{$matchRequest->uuid}/booking")->assertNotFound();
        $this->actingAs($intruder)->getJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => 'x'])->assertNotFound();
        bookIn($matchRequest, $intruder, $type->uuid)->assertNotFound();
    })->with(['particulier', 'prestataire', 'admin']);

    it('exige une relation acceptée, sans blocage, et un calendrier ouvert', function (): void {
        $client = projectOwner('particulier');
        $provider = appointmentProvider();
        weeklyAvailability($provider);
        $type = appointmentTypeOf($provider);
        $pending = pendingProposal($provider, publishedProjectOf($client));

        bookIn($pending, $client, $type->uuid)->assertConflict();

        [$client, $provider, $matchRequest, $type] = bookableRelation();
        UserBlock::query()->forceCreate(['blocker_id' => $provider->id, 'blocked_id' => $client->id]);
        bookIn($matchRequest, $client, $type->uuid)->assertForbidden();
        UserBlock::query()->delete();

        appointmentCalendarOf($provider)->forceFill(['is_enabled' => false])->save();
        bookIn($matchRequest, $client, $type->uuid)->assertConflict();
    });

    it('donne les types réservables et les créneaux aux deux participants, sans le détail du lieu', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation();

        foreach ([$client, $provider] as $user) {
            $this->actingAs($user)->getJson("/api/v1/me/match-requests/{$matchRequest->uuid}/booking?type={$type->uuid}&from=2027-03-22&to=2027-03-22")
                ->assertOk()
                ->assertJsonPath('data.types.0.uuid', $type->uuid)
                ->assertJsonMissingPath('data.types.0.location_details')
                ->assertJsonCount(6, 'data.slots');
        }
    });
});

describe('refus, annulation et expiration', function (): void {
    it('refuse avec un message chiffré et prévient l\'auteur', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation();
        $uuid = bookIn($matchRequest, $client, $type->uuid)->json('data.uuid');

        $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/decline")->assertForbidden();
        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/decline", ['message' => 'Je ne suis pas disponible cette semaine.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.notes.0.kind', 'decline');

        expect((string) AppointmentNote::query()->getQuery()->value('body'))->not->toContain('disponible');
        Notification::assertSentTo($client, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::Declined);
    });

    it('annule un rendez-vous confirmé par l\'une ou l\'autre partie, jusqu\'au début', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation(['auto_confirm' => true]);
        $uuid = bookIn($matchRequest, $client, $type->uuid)->json('data.uuid');

        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'teleportation'])->assertUnprocessable();
        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'schedule_conflict', 'message' => 'Un imprévu, désolé.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation', ['by' => 'provider', 'reason' => 'schedule_conflict'])
            ->assertJsonPath('data.location', null);

        Notification::assertSentTo($client, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::Cancelled);
        $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'other'])->assertConflict();
    });

    it('refuse l\'annulation d\'un rendez-vous commencé, et celle d\'une demande par la partie attendue', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation();
        $uuid = bookIn($matchRequest, $client, $type->uuid)->json('data.uuid');

        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'other'])->assertForbidden();
        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertOk();

        $this->travelTo(CarbonImmutable::parse('2027-03-22 08:05:00'));
        $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'other'])->assertConflict();
    });

    it('fait expirer une demande sans réponse au délai de prévenance et prévient son auteur', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation([], ['min_notice_minutes' => 30]);
        $uuid = bookIn($matchRequest, $client, $type->uuid, '2027-03-22T09:00:00Z')->json('data.uuid');

        expect(Appointment::query()->sole()->expires_at?->toIso8601ZuluString())->toBe('2027-03-22T08:30:00Z');

        $this->travelTo(CarbonImmutable::parse('2027-03-22 08:31:00'));
        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertConflict();
        $this->artisan('appointments:expire')->assertSuccessful();

        expect(Appointment::query()->sole()->status)->toBe(AppointmentStatus::Expired);
        Notification::assertSentTo($client, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::Expired);
    });

    it('cache le rendez-vous à tout autre utilisateur, admins compris', function (): void {
        [$client, , $matchRequest, $type] = bookableRelation();
        $uuid = bookIn($matchRequest, $client, $type->uuid)->json('data.uuid');

        foreach ([moderator(), browsingProvider(), projectOwner('particulier')] as $intruder) {
            $this->actingAs($intruder)->getJson("/api/v1/me/appointments/{$uuid}")->assertNotFound();
            $this->actingAs($intruder)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'x'])->assertNotFound();
            $this->actingAs($intruder)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertNotFound();
            $this->actingAs($intruder)->getJson('/api/v1/me/appointments')->assertOk()->assertJsonCount(0, 'data');
        }
    });

    it('liste les rendez-vous à venir et passés de l\'utilisateur', function (): void {
        [$client, $provider, $matchRequest, $type] = bookableRelation(['auto_confirm' => true]);
        bookIn($matchRequest, $client, $type->uuid)->assertCreated();
        bookIn($matchRequest, $client, $type->uuid, '2027-03-23T08:00:00Z')->assertCreated();

        $this->actingAs($client)->getJson('/api/v1/me/appointments')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.starts_at', FIRST_SLOT);
        $this->actingAs($provider)->getJson('/api/v1/me/appointments?role=provider')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($provider)->getJson('/api/v1/me/appointments?role=client')->assertOk()->assertJsonCount(0, 'data');

        $this->travelTo(CarbonImmutable::parse('2027-03-22 12:00:00'));
        $this->actingAs($client)->getJson('/api/v1/me/appointments?scope=past')->assertOk()->assertJsonCount(1, 'data');
    });
});
