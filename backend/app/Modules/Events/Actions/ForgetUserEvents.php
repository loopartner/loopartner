<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\CancelledBy;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRevision;

/**
 * Traite les données du module Events d'un utilisateur qui supprime son compte (RGPD).
 * Appelée dans la transaction de DeleteAccount (événement AccountDeleting), avant la suppression
 * des médias et de la ligne users.
 *
 * - Ses inscriptions : supprimées, et les places libérées.
 * - Ses révisions : supprimées (leurs couvertures le sont avec ses médias).
 * - Ses événements publiés pas encore terminés : annulés par la plateforme, les inscrits sont
 *   prévenus ; l'événement est ensuite détaché de son compte (user_id et organizer_name à null)
 *   pour que les inscrits voient l'annulation, puis purgé après
 *   security.retention.closed_events_months. Même détachement pour un événement déjà annulé et
 *   pas encore terminé.
 * - Ses autres événements (brouillons, en attente, refusés, passés) : supprimés avec leurs
 *   inscriptions et leurs annonces. Ils ne sont plus publics et ne servent plus à personne.
 * - Les décisions d'un admin restent dans le journal d'audit (sans clé étrangère).
 */
class ForgetUserEvents
{
    public function __construct(private CancelEvent $cancelEvent) {}

    public function handle(User $user): void
    {
        $this->forgetRegistrations($user);

        EventRevision::query()->where('user_id', $user->id)->delete();

        Event::query()
            ->where('user_id', $user->id)
            ->get()
            ->each(function (Event $event): void {
                $upcoming = $event->ends_at->isFuture();

                if ($upcoming && $event->status === EventStatus::Published) {
                    $event = $this->cancelEvent->handle($event, CancelledBy::System);
                }

                if ($upcoming && $event->status === EventStatus::Cancelled) {
                    $event->forceFill(['user_id' => null, 'organizer_name' => null])->save();

                    return;
                }

                $event->delete();
            });
    }

    private function forgetRegistrations(User $user): void
    {
        EventRegistration::query()
            ->where('user_id', $user->id)
            ->where('status', RegistrationStatus::Registered->value)
            ->pluck('event_id')
            ->each(function (int $eventId): void {
                $event = Event::query()->whereKey($eventId)->lockForUpdate()->first();
                $event?->forceFill(['registrations_count' => max(0, $event->registrations_count - 1)])->save();
            });

        EventRegistration::query()->where('user_id', $user->id)->delete();
    }
}
