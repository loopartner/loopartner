<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentOrigin;
use App\Modules\Appointments\Enums\AppointmentStatus;
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
 * La partie attendue refuse une demande ou une proposition, avec un message facultatif
 * (note signalable). Le créneau est libéré ; une réservation de la page publique refuse aussi
 * sa demande de contact (sans email du module Directory). L'auteur de la demande est prévenu.
 */
class DeclineAppointment
{
    public function __construct(private ManageAppointmentContact $contact) {}

    public function handle(User $actor, Appointment $appointment, ?string $message = null): Appointment
    {
        $appointment = DB::transaction(function () use ($actor, $appointment, $message): Appointment {
            ['appointment' => $appointment] = AppointmentLock::lock($appointment);
            $party = $appointment->partyOf($actor);
            $awaited = $appointment->awaitedParty();

            if ($awaited === null) {
                throw new ConflictHttpException('Ce rendez-vous n\'attend pas de réponse.');
            }

            if ($party !== $awaited) {
                throw new AccessDeniedHttpException('C\'est à l\'autre partie de répondre à cette demande.');
            }

            $appointment->forceFill([
                'status' => AppointmentStatus::Declined,
                'declined_at' => now(),
                'expires_at' => null,
            ])->save();

            SlotLedger::release($appointment);
            AppointmentNotes::add($appointment, $party, NoteKind::Decline, $message);

            if ($appointment->origin === AppointmentOrigin::PublicPage) {
                $this->contact->decline($appointment->match_request_id);
            }

            AppointmentNotification::sendTo($appointment->created_by === Party::Client ? $appointment->client : $appointment->provider, AppointmentNotice::Declined, $appointment);

            return $appointment;
        });

        SecurityLog::record('appointments.declined', ['user_id' => $actor->id, 'appointment' => $appointment->uuid]);

        return $appointment;
    }
}
