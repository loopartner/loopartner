<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentOrigin;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\AppointmentLock;
use App\Modules\Directory\Actions\IsBlockedBetween;
use App\Modules\Directory\Actions\ManageAppointmentContact;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * La partie attendue confirme une demande (prestataire) ou une proposition (client).
 *
 * - Autre partie : 403 ; rendez-vous qui n'attend pas de confirmation, expiré ou commencé : 409 ;
 *   blocage : 403.
 * - Proposition du prestataire pour un lieu que le client donne (chez lui, téléphone) : le
 *   client donne l'adresse ou le numéro en confirmant (422 sinon).
 * - Réservation de la page publique : la demande de contact qui l'accompagne est acceptée
 *   (conversation ouverte, nom du client révélé au prestataire).
 * - Les deux parties reçoivent la confirmation, avec un .ics (titre et dates).
 */
class ConfirmAppointment
{
    public function __construct(
        private IsBlockedBetween $isBlockedBetween,
        private ManageAppointmentContact $contact,
    ) {}

    /**
     * @param  array{address?: string|null, phone?: string|null}  $data
     */
    public function handle(User $actor, Appointment $appointment, array $data = []): Appointment
    {
        $appointment = DB::transaction(function () use ($actor, $appointment, $data): Appointment {
            ['appointment' => $appointment] = AppointmentLock::lock($appointment);
            $party = $appointment->partyOf($actor);
            $awaited = $appointment->awaitedParty();

            if ($awaited === null) {
                throw new ConflictHttpException('Ce rendez-vous n\'attend pas de confirmation.');
            }

            if ($party !== $awaited) {
                throw new AccessDeniedHttpException('C\'est à l\'autre partie de confirmer ce rendez-vous.');
            }

            if ($appointment->hasStarted() || ($appointment->expires_at !== null && ! $appointment->expires_at->isFuture())) {
                throw new ConflictHttpException('Cette demande a expiré.');
            }

            if ($appointment->provider_id === null || $appointment->client_id === null) {
                throw new ConflictHttpException('L\'autre participant n\'est plus inscrit.');
            }

            if ($this->isBlockedBetween->handle($appointment->provider_id, $appointment->client_id)) {
                throw new AccessDeniedHttpException('Vous ne pouvez pas confirmer ce rendez-vous.');
            }

            $this->completeClientLocation($appointment, $party, $data);

            if ($appointment->origin === AppointmentOrigin::PublicPage) {
                $this->contact->accept($appointment->match_request_id);
            }

            $now = now();
            $appointment->forceFill([
                'status' => AppointmentStatus::Confirmed,
                'confirmed_at' => $now,
                'scheduled_at' => $now,
                'expires_at' => null,
            ])->save();

            AppointmentNotification::sendTo($appointment->provider, AppointmentNotice::Confirmed, $appointment);
            AppointmentNotification::sendTo($appointment->client, AppointmentNotice::Confirmed, $appointment);

            return $appointment;
        });

        SecurityLog::record('appointments.confirmed', ['user_id' => $actor->id, 'appointment' => $appointment->uuid]);

        return $appointment;
    }

    /**
     * @param  array{address?: string|null, phone?: string|null}  $data
     *
     * @throws ValidationException
     */
    private function completeClientLocation(Appointment $appointment, ?Party $party, array $data): void
    {
        $mode = $appointment->location_mode;

        if ($party !== Party::Client || ! $mode->detailsFromClient() || ($appointment->location !== null && isset($appointment->location[$mode->detailKey()]))) {
            return;
        }

        $value = $data[$mode->detailKey()] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([$mode->detailKey() => $mode->detailKey() === 'phone'
                ? 'Indiquez le numéro auquel le prestataire vous appellera.'
                : 'Indiquez l\'adresse du rendez-vous.']);
        }

        $appointment->location = new \ArrayObject([$mode->detailKey() => trim($value)]);
    }
}
