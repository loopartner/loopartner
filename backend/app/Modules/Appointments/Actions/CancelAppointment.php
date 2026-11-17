<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentOrigin;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\CancellationReason;
use App\Modules\Appointments\Enums\CancelledBy;
use App\Modules\Appointments\Enums\NoteKind;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\AppointmentLock;
use App\Modules\Appointments\Support\AppointmentNotes;
use App\Modules\Appointments\Support\SlotLedger;
use App\Modules\Directory\Actions\ManageAppointmentContact;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Annulation par une partie (décision 55) : jusqu'au début, sans frais, motif en liste fermée et
 * message facultatif (note signalable). Une demande ou une proposition en attente ne s'annule
 * que par son auteur (l'autre partie la refuse). Tous les créneaux retenus (et un report en
 * attente) sont libérés ; l'autre partie est prévenue. Une réservation de la page publique en
 * attente retire aussi sa demande de contact.
 */
class CancelAppointment
{
    public function __construct(private ManageAppointmentContact $contact) {}

    public function handle(User $actor, Appointment $appointment, CancellationReason $reason, ?string $message = null): Appointment
    {
        $appointment = DB::transaction(function () use ($actor, $appointment, $reason, $message): Appointment {
            ['appointment' => $appointment] = AppointmentLock::lock($appointment);
            $party = $appointment->partyOf($actor);

            if ($party === null) {
                throw new AccessDeniedHttpException('Vous ne participez pas à ce rendez-vous.');
            }

            if (! $appointment->status->isActive()) {
                throw new ConflictHttpException('Ce rendez-vous est déjà clos.');
            }

            if ($appointment->hasStarted()) {
                throw new ConflictHttpException('Un rendez-vous commencé ne s\'annule plus.');
            }

            if ($appointment->awaitedParty() === $party) {
                throw new AccessDeniedHttpException('Refusez la demande plutôt que de l\'annuler.');
            }

            $wasPending = $appointment->status->isPending();

            $appointment->forceFill([
                'status' => AppointmentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $party === Party::Client ? CancelledBy::Client : CancelledBy::Provider,
                'cancellation_reason' => $reason,
                'expires_at' => null,
                'change_starts_at' => null,
                'change_ends_at' => null,
                'change_by' => null,
                'change_expires_at' => null,
            ])->save();

            SlotLedger::release($appointment);
            AppointmentNotes::add($appointment, $party, NoteKind::Cancellation, $message);

            if ($wasPending && $appointment->origin === AppointmentOrigin::PublicPage) {
                $this->contact->withdraw($appointment->match_request_id);
            }

            AppointmentNotification::sendTo($party === Party::Client ? $appointment->provider : $appointment->client, AppointmentNotice::Cancelled, $appointment);

            return $appointment;
        });

        SecurityLog::record('appointments.cancelled', [
            'user_id' => $actor->id,
            'appointment' => $appointment->uuid,
            'by' => $appointment->cancelled_by?->value,
            'reason' => $reason->value,
        ]);

        return $appointment;
    }
}
