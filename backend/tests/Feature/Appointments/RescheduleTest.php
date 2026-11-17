<?php

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\HoldKind;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\ReminderDelivery;
use App\Modules\Appointments\Models\SlotHold;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Directory\Actions\AcceptMatchRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
| Report d'un rendez-vous confirmé (décision 55) : proposition retenue jusqu'à la réponse de
| l'autre partie, déplacement direct sur un type en confirmation automatique, et libération des
| créneaux (ancien créneau d'un report accepté, créneau proposé d'un report refusé), index
| unique compris.
|
| Horloge : lundi 22 mars 2027, 08:00 à Paris ; plages de 09:00 à 12:00 tous les jours.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
});

/**
 * Rendez-vous confirmé lundi 09:00 (08:00 UTC) : [client, prestataire, uuid du rendez-vous].
 *
 * @return array{0: User, 1: User, 2: string}
 */
function confirmedAppointment(bool $autoConfirm = false): array
{
    [$client, $provider, $matchRequest] = appointmentRelation();
    weeklyAvailability($provider);
    $type = appointmentTypeOf($provider, ['auto_confirm' => $autoConfirm]);

    $uuid = test()->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => '2027-03-22T08:00:00Z'])->assertCreated()->json('data.uuid');

    if (! $autoConfirm) {
        test()->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/confirm")->assertOk();
    }

    return [$client, $provider, $uuid];
}

/**
 * Un autre client du même prestataire réserve le créneau donné : la réponse dit s'il est libre.
 */
function otherClientBooks(User $provider, string $startsAt): TestResponse
{
    $other = projectOwner('particulier');
    $matchRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf($other)));
    $type = appointmentCalendarOf($provider)->types()->firstOrFail();

    return test()->actingAs($other)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => $startsAt]);
}

it('retient le créneau proposé jusqu\'à la réponse, puis déplace le rendez-vous et libère l\'ancien créneau', function (): void {
    [$client, $provider, $uuid] = confirmedAppointment();
    ReminderDelivery::query()->forceCreate(['appointment_id' => Appointment::query()->sole()->id, 'recipient_side' => Party::Client, 'offset_minutes' => 1440, 'sent_at' => now()]);

    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-23T09:00:00Z', 'message' => 'Un imprévu lundi.'])
        ->assertOk()
        ->assertJsonPath('data.starts_at', '2027-03-22T08:00:00Z')
        ->assertJsonPath('data.pending_change.starts_at', '2027-03-23T09:00:00Z')
        ->assertJsonPath('data.pending_change.proposed_by_me', true);

    Notification::assertSentTo($client, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::ChangeProposed);
    expect(SlotHold::query()->pluck('kind')->map->value->sort()->values()->all())->toBe(['current', 'proposed']);
    otherClientBooks($provider, '2027-03-23T09:00:00Z')->assertConflict();

    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/accept-change")->assertForbidden();
    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/accept-change")
        ->assertOk()
        ->assertJsonPath('data.starts_at', '2027-03-23T09:00:00Z')
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.pending_change', null)
        ->assertJsonPath('data.reschedules_left', 2);

    Notification::assertSentTo([$client, $provider], AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::ChangeAccepted);
    expect(SlotHold::query()->sole()->kind)->toBe(HoldKind::Current)
        ->and(ReminderDelivery::query()->count())->toBe(0);

    // L'ancien créneau (lundi 09:00) est de nouveau réservable, y compris pour l'index unique.
    otherClientBooks($provider, '2027-03-22T08:00:00Z')->assertCreated();
});

it('libère le créneau proposé quand le report est refusé, et garde l\'horaire actuel', function (): void {
    [$client, $provider, $uuid] = confirmedAppointment();

    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-23T09:00:00Z'])->assertOk();
    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/decline-change")
        ->assertOk()
        ->assertJsonPath('data.starts_at', '2027-03-22T08:00:00Z')
        ->assertJsonPath('data.pending_change', null);

    Notification::assertSentTo($client, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::ChangeDeclined);
    expect(SlotHold::query()->sole()->kind)->toBe(HoldKind::Current);
    otherClientBooks($provider, '2027-03-23T09:00:00Z')->assertCreated();
});

it('laisse l\'auteur retirer sa proposition, sans email', function (): void {
    [$client, $provider, $uuid] = confirmedAppointment();

    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-23T09:00:00Z'])->assertOk();
    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/decline-change")->assertOk();

    Notification::assertNotSentTo($client, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::ChangeDeclined);
    expect(SlotHold::query()->count())->toBe(1);
});

it('déplace directement le client sur un type en confirmation automatique', function (): void {
    [$client, $provider, $uuid] = confirmedAppointment(autoConfirm: true);

    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-22T08:30:00Z'])
        ->assertOk()
        ->assertJsonPath('data.starts_at', '2027-03-22T08:30:00Z')
        ->assertJsonPath('data.pending_change', null)
        ->assertJsonPath('data.version', 2);

    expect(SlotHold::query()->sole()->slot_key)->toEndWith('2027-03-22T08:30');
    Notification::assertSentTo($provider, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::ChangeAccepted);
    otherClientBooks($provider, '2027-03-22T08:00:00Z')->assertCreated();
});

it('libère le créneau proposé d\'un report resté sans réponse', function (): void {
    [$client, $provider, $uuid] = confirmedAppointment();
    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-24T09:00:00Z'])->assertOk();

    // Le report expire au plus tard au début de l'horaire actuel (lundi 09:00).
    $this->travelTo(CarbonImmutable::parse('2027-03-22 08:00:00'));
    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/accept-change")->assertConflict();
    $this->artisan('appointments:expire')->assertSuccessful();

    expect(Appointment::query()->sole()->change_starts_at)->toBeNull()
        ->and(SlotHold::query()->sole()->kind)->toBe(HoldKind::Current);
    Notification::assertSentTo($client, AppointmentNotification::class, fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::ChangeExpired);
});

it('refuse un report impossible', function (): void {
    [$client, $provider, $uuid] = confirmedAppointment();

    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-22T08:00:00Z'])->assertUnprocessable();
    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-22T14:00:00Z'])->assertConflict()->assertJsonPath('code', 'slot_unavailable');

    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-23T09:00:00Z'])->assertOk();
    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-23T09:30:00Z'])
        ->assertConflict()
        ->assertJsonPath('message', 'Un report est déjà en attente de réponse.');
});

it('limite les reports à trois par rendez-vous', function (): void {
    [$client, $provider, $uuid] = confirmedAppointment();

    foreach (['2027-03-23T08:00:00Z', '2027-03-23T08:30:00Z', '2027-03-23T09:00:00Z'] as $startsAt) {
        $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => $startsAt])->assertOk();
        $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/decline-change")->assertOk();
    }

    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-23T09:30:00Z'])->assertConflict();
});

it('cache le report à tout autre utilisateur', function (): void {
    [, , $uuid] = confirmedAppointment();
    $intruder = browsingProvider();

    $this->actingAs($intruder)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => 'nope'])->assertNotFound();
    $this->actingAs($intruder)->postJson("/api/v1/me/appointments/{$uuid}/accept-change")->assertNotFound();
    $this->actingAs($intruder)->postJson("/api/v1/me/appointments/{$uuid}/decline-change")->assertNotFound();
});
