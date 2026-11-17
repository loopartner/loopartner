<?php

namespace App\Modules\Appointments\Actions;

use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentOrigin;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\HoldKind;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\AppointmentLock;
use App\Modules\Appointments\Support\SlotLedger;
use App\Modules\Directory\Actions\ManageAppointmentContact;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Tâche appointments:expire (toutes les 15 minutes) :
 *
 * - demandes et propositions restées sans réponse (expires_at dépassé : 48 heures, ou le délai
 *   de prévenance avant le début) : « expired », créneau libéré, auteur prévenu ; une
 *   réservation de la page publique fait aussi expirer sa demande de contact ;
 * - reports proposés restés sans réponse : abandonnés, créneau proposé libéré, auteur prévenu ;
 *   le rendez-vous reste à son horaire.
 *
 * Chaque rendez-vous est traité sous verrou et revérifié : une réponse arrivée entre-temps
 * l'emporte. Relancer la tâche est sans effet.
 *
 * @return array{requests: int, changes: int}
 */
class ExpireAppointments
{
    public function __construct(private ManageAppointmentContact $contact) {}

    /**
     * @return array{requests: int, changes: int}
     */
    public function handle(): array
    {
        $requests = 0;
        $changes = 0;

        Appointment::query()
            ->whereIn('status', [AppointmentStatus::Requested->value, AppointmentStatus::Proposed->value])
            ->where('expires_at', '<=', now())
            ->lazyById()
            ->each(function (Appointment $appointment) use (&$requests): void {
                $requests += $this->expireRequest($appointment) ? 1 : 0;
            });

        Appointment::query()
            ->where('status', AppointmentStatus::Confirmed->value)
            ->whereNotNull('change_expires_at')
            ->where('change_expires_at', '<=', now())
            ->lazyById()
            ->each(function (Appointment $appointment) use (&$changes): void {
                $changes += $this->expireChange($appointment) ? 1 : 0;
            });

        return ['requests' => $requests, 'changes' => $changes];
    }

    private function expireRequest(Appointment $appointment): bool
    {
        return DB::transaction(function () use ($appointment): bool {
            ['appointment' => $appointment] = AppointmentLock::lock($appointment);

            if (! $appointment->status->isPending() || $appointment->expires_at === null || $appointment->expires_at->isFuture()) {
                return false;
            }

            $appointment->forceFill(['status' => AppointmentStatus::Expired, 'expired_at' => now(), 'expires_at' => null])->save();
            SlotLedger::release($appointment);

            if ($appointment->origin === AppointmentOrigin::PublicPage) {
                $this->contact->expire($appointment->match_request_id);
            }

            AppointmentNotification::sendTo($appointment->created_by === Party::Client ? $appointment->client : $appointment->provider, AppointmentNotice::Expired, $appointment);
            SecurityLog::record('appointments.expired', ['user_id' => null, 'appointment' => $appointment->uuid]);

            return true;
        });
    }

    private function expireChange(Appointment $appointment): bool
    {
        return DB::transaction(function () use ($appointment): bool {
            ['appointment' => $appointment] = AppointmentLock::lock($appointment);

            if (! $appointment->hasPendingChange() || $appointment->change_expires_at === null || $appointment->change_expires_at->isFuture()) {
                return false;
            }

            $proposer = $appointment->change_by;
            $appointment->forceFill(['change_starts_at' => null, 'change_ends_at' => null, 'change_by' => null, 'change_expires_at' => null])->save();
            SlotLedger::release($appointment, HoldKind::Proposed);

            AppointmentNotification::sendTo($proposer === Party::Client ? $appointment->client : $appointment->provider, AppointmentNotice::ChangeExpired, $appointment);

            return true;
        });
    }
}
