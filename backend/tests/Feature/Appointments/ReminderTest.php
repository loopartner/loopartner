<?php

use App\Models\User;
use App\Modules\Accounts\Models\PremiumGrant;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\ReminderDelivery;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Rappels des rendez-vous (décisions 54 et 56) : par le planificateur, aux deux parties, en
| durée réelle, jamais deux fois.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
});

/**
 * Rendez-vous confirmé au début donné (UTC) : [client, prestataire, uuid].
 *
 * @return array{0: User, 1: User, 2: string}
 */
function confirmedAt(string $startsAt, array $calendar = [], bool $premium = false): array
{
    [$client, $provider, $matchRequest] = appointmentRelation(calendar: ['horizon_days' => 30, ...$calendar]);

    if ($premium) {
        makePremium($provider);
    }

    weeklyAvailability($provider, array_map(fn (int $day): array => [$day, 0, 24 * 60], range(1, 7)));
    $type = appointmentTypeOf($provider, ['auto_confirm' => true]);
    $uuid = test()->actingAs($client)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/appointments", ['type' => $type->uuid, 'starts_at' => $startsAt])->assertCreated()->json('data.uuid');

    return [$client, $provider, $uuid];
}

function remindersSentTo(User $user): int
{
    return Notification::sent($user, AppointmentNotification::class)
        ->filter(fn (AppointmentNotification $notification): bool => $notification->notice === AppointmentNotice::Reminder)
        ->count();
}

it('rappelle le rendez-vous aux deux parties 24 heures avant, une seule fois', function (): void {
    [$client, $provider] = confirmedAt('2027-03-23T08:00:00Z');

    $this->artisan('appointments:send-reminders')->assertSuccessful();
    expect(remindersSentTo($client))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2027-03-22 08:00:00'));
    $this->artisan('appointments:send-reminders')->assertSuccessful();
    $this->artisan('appointments:send-reminders')->assertSuccessful();

    expect(remindersSentTo($client))->toBe(1)
        ->and(remindersSentTo($provider))->toBe(1)
        ->and(ReminderDelivery::query()->count())->toBe(2);
});

it('n\'envoie pas un rappel dont l\'heure était déjà passée à la confirmation', function (): void {
    [$client] = confirmedAt('2027-03-22T20:00:00Z');

    $this->travelTo(CarbonImmutable::parse('2027-03-22 19:00:00'));
    $this->artisan('appointments:send-reminders')->assertSuccessful();

    expect(remindersSentTo($client))->toBe(0);
});

it('envoie les deux rappels choisis par un prestataire Premium, et revient au rappel gratuit sans Premium', function (): void {
    [$client, $provider] = confirmedAt('2027-03-25T08:00:00Z', ['reminder_offsets' => [120, 2880]], premium: true);

    $this->travelTo(CarbonImmutable::parse('2027-03-23 08:00:00'));
    $this->artisan('appointments:send-reminders');
    $this->travelTo(CarbonImmutable::parse('2027-03-25 06:00:00'));
    $this->artisan('appointments:send-reminders');

    expect(remindersSentTo($client))->toBe(2);

    PremiumGrant::query()->update(['revoked_at' => now()]);
    [$other] = confirmedAt('2027-03-27T08:00:00Z', ['reminder_offsets' => [120, 2880]]);
    $this->travelTo(CarbonImmutable::parse('2027-03-26 08:00:00'));
    $this->artisan('appointments:send-reminders');

    expect(remindersSentTo($other))->toBe(1);
});

it('compte les 24 heures en durée réelle à travers le passage à l\'heure d\'été', function (): void {
    // Dimanche 28 mars 2027 à 10:00 (heure d'été, 08:00 UTC) : rappel samedi à 09:00 heure de Paris.
    [$client] = confirmedAt('2027-03-28T08:00:00Z');

    $this->travelTo(CarbonImmutable::parse('2027-03-27 07:59:00'));
    $this->artisan('appointments:send-reminders');
    expect(remindersSentTo($client))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2027-03-27 08:00:00'));
    $this->artisan('appointments:send-reminders');
    expect(remindersSentTo($client))->toBe(1);
});

it('ne rappelle pas un rendez-vous annulé', function (): void {
    [$client, , $uuid] = confirmedAt('2027-03-23T08:00:00Z');
    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/cancel", ['reason' => 'other'])->assertOk();

    $this->travelTo(CarbonImmutable::parse('2027-03-22 08:00:00'));
    $this->artisan('appointments:send-reminders');

    expect(remindersSentTo($client))->toBe(0);
});

it('rappelle de nouveau après un report accepté', function (): void {
    [$client, $provider, $uuid] = confirmedAt('2027-03-23T08:00:00Z');
    $this->travelTo(CarbonImmutable::parse('2027-03-22 08:00:00'));
    $this->artisan('appointments:send-reminders');

    $this->actingAs($provider)->postJson("/api/v1/me/appointments/{$uuid}/reschedule", ['starts_at' => '2027-03-24T08:00:00Z'])->assertOk();
    $this->actingAs($client)->postJson("/api/v1/me/appointments/{$uuid}/accept-change")->assertOk();

    $this->travelTo(CarbonImmutable::parse('2027-03-23 08:00:00'));
    $this->artisan('appointments:send-reminders');

    expect(remindersSentTo($client))->toBe(2);
});

it('envoie un rappel sans lieu, ni lien, ni note, dans une catégorie facultative', function (): void {
    [$client, , $uuid] = confirmedAt('2027-03-23T08:00:00Z');
    $notification = new AppointmentNotification(AppointmentNotice::Reminder, Appointment::query()->where('uuid', $uuid)->sole());
    $mail = $notification->toMail($client);

    expect($notification->notificationCategory())->toBe('appointments.reminders')
        ->and(implode("\n", $mail->introLines))->not->toContain('visio.exemple.fr')
        ->and($mail->rawAttachments)->toBe([]);
});
