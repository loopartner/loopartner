<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\HoldKind;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\AppointmentLock;
use App\Modules\Appointments\Support\SlotLedger;
use App\Modules\Directory\Actions\IsBlockedBetween;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Réponse à un report proposé (décision 55).
 *
 * - accept() : par l'autre partie ; l'ancien créneau est libéré, le créneau proposé devient
 *   celui du rendez-vous (version + 1, rappels repartis à zéro) ; les deux parties reçoivent
 *   le nouvel horaire avec un .ics.
 * - decline() : par l'autre partie (l'auteur est prévenu), ou par l'auteur lui-même pour
 *   retirer sa proposition (sans email) ; le créneau proposé est libéré, l'horaire actuel reste.
 *
 * Aucun report en attente, ou report expiré : 409 ; auteur qui tente d'accepter : 403.
 */
class AnswerReschedule
{
    public function __construct(private IsBlockedBetween $isBlockedBetween) {}

    public function accept(User $actor, Appointment $appointment): Appointment
    {
        $appointment = DB::transaction(function () use ($actor, $appointment): Appointment {
            ['appointment' => $appointment] = AppointmentLock::lock($appointment);
            $party = $this->pendingFor($appointment, $actor);

            if ($party === $appointment->change_by) {
                throw new AccessDeniedHttpException('C\'est à l\'autre partie de répondre à ce report.');
            }

            if ($appointment->provider_id === null || $appointment->client_id === null || $this->isBlockedBetween->handle($appointment->provider_id, $appointment->client_id)) {
                throw new AccessDeniedHttpException('Vous ne pouvez pas accepter ce report.');
            }

            $startsAt = CarbonImmutable::parse($appointment->change_starts_at);
            $endsAt = CarbonImmutable::parse($appointment->change_ends_at);

            SlotLedger::promoteProposed($appointment);
            RescheduleAppointment::moveTo($appointment, $startsAt, $endsAt);

            AppointmentNotification::sendTo($appointment->client, AppointmentNotice::ChangeAccepted, $appointment);
            AppointmentNotification::sendTo($appointment->provider, AppointmentNotice::ChangeAccepted, $appointment);

            return $appointment;
        });

        SecurityLog::record('appointments.rescheduled', ['user_id' => $actor->id, 'appointment' => $appointment->uuid]);

        return $appointment;
    }

    public function decline(User $actor, Appointment $appointment): Appointment
    {
        $appointment = DB::transaction(function () use ($actor, $appointment): Appointment {
            ['appointment' => $appointment] = AppointmentLock::lock($appointment);
            $party = $this->pendingFor($appointment, $actor);
            $proposer = $appointment->change_by;

            $appointment->forceFill(['change_starts_at' => null, 'change_ends_at' => null, 'change_by' => null, 'change_expires_at' => null])->save();
            SlotLedger::release($appointment, HoldKind::Proposed);

            if ($party !== $proposer) {
                AppointmentNotification::sendTo($proposer === Party::Client ? $appointment->client : $appointment->provider, AppointmentNotice::ChangeDeclined, $appointment);
            }

            return $appointment;
        });

        SecurityLog::record('appointments.reschedule_declined', ['user_id' => $actor->id, 'appointment' => $appointment->uuid]);

        return $appointment;
    }

    private function pendingFor(Appointment $appointment, User $actor): Party
    {
        $party = $appointment->partyOf($actor);

        if ($party === null) {
            throw new AccessDeniedHttpException('Vous ne participez pas à ce rendez-vous.');
        }

        if (! $appointment->hasPendingChange() || $appointment->change_expires_at === null || ! $appointment->change_expires_at->isFuture()) {
            throw new ConflictHttpException('Aucun report n\'est en attente de réponse.');
        }

        return $party;
    }
}
