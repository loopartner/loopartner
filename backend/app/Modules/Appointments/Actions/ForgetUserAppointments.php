<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\CancelledBy;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\SlotLedger;

/**
 * Traite les rendez-vous d'un utilisateur qui supprime son compte (RGPD). Appelée dans la
 * transaction de DeleteAccount (événement AccountDeleting), avant la suppression de la ligne
 * users.
 *
 * - Rendez-vous à venir (en attente ou confirmés) : annulés par la plateforme
 *   (cancelled_by = system), créneaux libérés, l'autre partie prévenue sobrement.
 * - Lieu donné par la personne (son adresse ou son téléphone de client, l'adresse ou le lien de
 *   son type de prestataire) : effacé tout de suite.
 * - Rendez-vous passés : conservés pour l'autre partie, sans lien avec le compte supprimé
 *   (clés étrangères à null), jusqu'à leur purge ou celle de leur conversation.
 * - Calendrier, types, plages et exceptions : supprimés (cascade du calendrier).
 * - Textes libres déjà écrits : conservés pour l'autre partie sans lien avec le compte (comme
 *   les messages), effacés à leur durée de conservation.
 */
class ForgetUserAppointments
{
    public function handle(User $user): void
    {
        Appointment::query()
            ->where(fn ($query) => $query->where('client_id', $user->id)->orWhere('provider_id', $user->id))
            ->with(['client', 'provider'])
            ->lazyById()
            ->each(function (Appointment $appointment) use ($user): void {
                $party = $appointment->partyOf($user);

                if ($party === null) {
                    return;
                }

                $changes = $this->erasedLocation($appointment, $party);

                if ($appointment->status->isActive() && $appointment->starts_at->isFuture()) {
                    $changes += [
                        'status' => AppointmentStatus::Cancelled,
                        'cancelled_at' => now(),
                        'cancelled_by' => CancelledBy::System,
                        'expires_at' => null,
                        'change_starts_at' => null,
                        'change_ends_at' => null,
                        'change_by' => null,
                        'change_expires_at' => null,
                    ];
                    SlotLedger::release($appointment);
                    AppointmentNotification::sendTo($party === Party::Client ? $appointment->provider : $appointment->client, AppointmentNotice::Cancelled, $appointment);
                }

                if ($changes !== []) {
                    $appointment->forceFill($changes)->save();
                }
            });

        Calendar::query()->where('user_id', $user->id)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function erasedLocation(Appointment $appointment, Party $party): array
    {
        $mode = $appointment->location_mode;
        $givenByUser = $party === Party::Client ? $mode->detailsFromClient() : $mode->detailsOnType();

        return $givenByUser && $appointment->location !== null ? ['location' => null, 'location_erased_at' => now()] : [];
    }
}
