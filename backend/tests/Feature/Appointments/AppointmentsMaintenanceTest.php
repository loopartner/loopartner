<?php

use App\Models\User;
use App\Modules\Accounts\Actions\DeleteAccount;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\CancelledBy;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentNote;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\AvailabilityException;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Models\ReminderDelivery;
use App\Modules\Appointments\Models\SlotHold;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\AppointmentsPersonalData;
use App\Support\Moderation\ModerationTargets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| Signalement des textes libres, suppression de compte, minimisation (décision 57) et données
| personnelles pour l'export du lot 11 (décision 59).
|
| Horloge : lundi 22 mars 2027, 08:00 à Paris.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
});

/**
 * Rendez-vous pris par le client (lieu : son téléphone), avec un motif : [client, prestataire, uuid].
 *
 * @return array{0: User, 1: User, 2: string}
 */
function bookedWithNote(bool $confirmed = true, string $startsAt = '2027-03-23T08:00:00Z'): array
{
    [$client, $provider, $matchRequest] = appointmentRelation();
    weeklyAvailability($provider);
    $type = appointmentTypeOf($provider, ['location_mode' => 'phone', 'location_details' => null, 'auto_confirm' => $confirmed]);

    $uuid = test()->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", [
        'type' => $type->uuid,
        'starts_at' => $startsAt,
        'phone' => '+33 6 12 34 56 78',
        'note' => 'Je souhaite parler de mon projet de cuisine.',
    ])->assertCreated()->json('data.uuid');

    return [$client, $provider, $uuid];
}

describe('signalement des textes libres', function (): void {
    it('laisse l\'autre partie signaler un texte, une seule fois tant qu\'il est examiné', function (): void {
        [$client, $provider, $uuid] = bookedWithNote();
        $note = AppointmentNote::query()->sole();

        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/notes/{$note->uuid}/report", ['category' => 'hate_harassment', 'comment' => 'Propos déplacés.'])
            ->assertCreated()
            ->assertJsonPath('data.target_type', 'appointment_note');
        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/notes/{$note->uuid}/report", ['category' => 'hate_harassment'])->assertConflict();

        $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/notes/{$note->uuid}/report", ['category' => 'hate_harassment'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs(browsingProvider())->postJson("/api/v1/me/appointments/{$uuid}/notes/{$note->uuid}/report", ['category' => 'hate_harassment'])->assertNotFound();
        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/notes/".Str::uuid().'/report', ['category' => 'hate_harassment'])->assertNotFound();
        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/notes/{$note->uuid}/report", ['category' => 'inconnue'])->assertUnprocessable();

        expect(json_encode(securityLog('moderation.report_created')[0]->context))->not->toContain('cuisine');
    });

    it('cache un texte masqué par la modération sans lui faire perdre sa place', function (): void {
        [$client, $provider, $uuid] = bookedWithNote();
        $note = AppointmentNote::query()->sole();

        expect(ModerationTargets::get('appointment_note')->findVisible($note->uuid, $client))->toBeNull()
            ->and(ModerationTargets::get('appointment_note')->findVisible($note->uuid, $provider)?->text)->toBe('Je souhaite parler de mon projet de cuisine.')
            ->and(ModerationTargets::get('appointment_note')->publiclyReportable())->toBeFalse();

        restrictContent('appointment_note', $note->uuid);

        $this->actingAs($provider)->getJson("/api/v1/me/appointments/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.notes.0.body', null)
            ->assertJsonPath('data.notes.0.restricted', true);
    });
});

describe('suppression de compte', function (): void {
    it('annule les rendez-vous à venir du client, prévient le prestataire et efface le lieu qu\'il a donné', function (): void {
        [$client, $provider, $uuid] = bookedWithNote();

        app(DeleteAccount::class)->handle($client->fresh());

        $appointment = Appointment::query()->where('uuid', $uuid)->sole();
        expect($appointment->status)->toBe(AppointmentStatus::Cancelled)
            ->and($appointment->cancelled_by)->toBe(CancelledBy::System)
            ->and($appointment->client_id)->toBeNull()
            ->and($appointment->location)->toBeNull()
            ->and($appointment->location_erased_at)->not->toBeNull()
            ->and(SlotHold::query()->count())->toBe(0)
            ->and(AppointmentNote::query()->sole()->author_id)->toBeNull();
        Notification::assertSentTo($provider, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::Cancelled);

        $this->actingAs($provider)->getJson("/api/v1/me/appointments/{$uuid}")->assertOk()->assertJsonPath('data.counterpart', ['deleted' => true]);
    });

    it('supprime le calendrier du prestataire et garde ses rendez-vous passés pour le client', function (): void {
        [$client, $provider, $uuid] = bookedWithNote();
        $this->travelTo(CarbonImmutable::parse('2027-03-24 07:00:00'));

        app(DeleteAccount::class)->handle($provider->fresh());

        $appointment = Appointment::query()->where('uuid', $uuid)->sole();
        expect($appointment->status)->toBe(AppointmentStatus::Confirmed)
            ->and($appointment->provider_id)->toBeNull()
            ->and($appointment->calendar_id)->toBeNull()
            ->and($appointment->location)->not->toBeNull()
            ->and(Calendar::query()->count())->toBe(0)
            ->and(AppointmentType::query()->count())->toBe(0);
        $this->actingAs($client)->getJson("/api/v1/me/appointments/{$uuid}")->assertOk()->assertJsonPath('data.counterpart', ['deleted' => true]);
    });
});

describe('minimisation', function (): void {
    it('efface le lieu et les notes trois mois après le rendez-vous, puis le supprime après 24 mois', function (): void {
        [, , $uuid] = bookedWithNote();

        $this->travelTo(CarbonImmutable::parse('2027-06-22 07:00:00'));
        $this->artisan('appointments:prune')->assertSuccessful();
        expect(Appointment::query()->sole()->location)->not->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2027-06-24 07:00:00'));
        $this->artisan('appointments:prune')->assertSuccessful();
        $appointment = Appointment::query()->where('uuid', $uuid)->sole();
        expect($appointment->location)->toBeNull()
            ->and($appointment->location_erased_at)->not->toBeNull()
            ->and(AppointmentNote::query()->sole()->body)->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2029-03-24 07:00:00'));
        $this->artisan('appointments:prune')->assertSuccessful();
        expect(Appointment::query()->count())->toBe(0);
    });

    it('supprime un rendez-vous annulé douze mois après son annulation, même après l\'effacement de ses détails', function (): void {
        [$client, , $uuid] = bookedWithNote(confirmed: false);
        $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'other'])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2027-07-01 07:00:00'));
        $this->artisan('appointments:prune');
        expect(Appointment::query()->sole()->location)->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2028-03-21 07:00:00'));
        $this->artisan('appointments:prune');
        expect(Appointment::query()->count())->toBe(1);

        $this->travelTo(CarbonImmutable::parse('2028-03-23 07:00:00'));
        $this->artisan('appointments:prune');
        expect(Appointment::query()->count())->toBe(0);
    });

    it('supprime les traces de rappel et les exceptions passées après trois mois', function (): void {
        [, $provider] = bookedWithNote();
        $calendar = Calendar::query()->where('user_id', $provider->id)->sole();
        ReminderDelivery::query()->forceCreate(['appointment_id' => Appointment::query()->sole()->id, 'recipient_side' => 'client', 'offset_minutes' => 1440, 'sent_at' => now()]);
        AvailabilityException::query()->forceCreate(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id, 'date' => '2027-03-25', 'kind' => 'closed']);

        $this->travelTo(CarbonImmutable::parse('2027-06-26 07:00:00'));
        $this->artisan('appointments:prune');

        expect(ReminderDelivery::query()->count())->toBe(0)
            ->and(AvailabilityException::query()->count())->toBe(0);
    });
});

describe('données personnelles (export du lot 11)', function (): void {
    it('rassemble ce que l\'utilisateur a fourni et ses rendez-vous, sans le texte de l\'autre partie', function (): void {
        [$client, $provider, $uuid] = bookedWithNote(confirmed: false);
        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/decline", ['message' => 'Je ne suis pas disponible.'])->assertOk();

        $forClient = AppointmentsPersonalData::for($client);
        $forProvider = AppointmentsPersonalData::for($provider);

        expect($forClient['calendar'])->toBeNull()
            ->and($forClient['appointments'][0])->toMatchArray(['uuid' => $uuid, 'role' => 'client', 'status' => 'declined', 'location_given' => ['phone' => '+33 6 12 34 56 78']])
            ->and($forClient['appointments'][0]['my_notes'])->toBe([['kind' => 'booking', 'body' => 'Je souhaite parler de mon projet de cuisine.', 'created_at' => '2027-03-22T07:00:00Z']])
            ->and($forProvider['calendar'])->toMatchArray(['timezone' => 'Europe/Paris'])
            ->and($forProvider['appointment_types'])->toHaveCount(1)
            ->and($forProvider['availability_rules'])->toHaveCount(7)
            ->and($forProvider['appointments'][0]['location_given'])->toBeNull()
            ->and(array_column($forProvider['appointments'][0]['my_notes'], 'body'))->toBe(['Je ne suis pas disponible.']);

        expect(json_encode($forClient))->not->toContain('disponible')
            ->and(json_encode($forProvider))->not->toContain('cuisine');
        // Le numéro donné par la personne lui est rendu ; aucun identifiant interne.
        expect(array_keys($forClient['appointments'][0]))->not->toContain('id')
            ->and(json_encode($forClient))->not->toContain('"client_id"')
            ->and(json_encode($forProvider))->not->toContain('"user_id"');
    });
});
