<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Enums\CancelledBy;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\Attendees;
use App\Modules\Events\Support\PendingRevisions;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Annule un événement publié et pas encore terminé, immédiatement et sans validation :
 * par son organisateur, par un admin (motif obligatoire, ModerateEvent) ou par la plateforme
 * (suppression du compte de l'organisateur).
 *
 * - La révision et les annonces en attente deviennent sans objet.
 * - Chaque inscrit est prévenu par email (sans le motif, lisible sur la page de l'événement).
 * - Les inscriptions sont conservées : les inscrits voient l'annulation dans leurs inscriptions.
 */
class CancelEvent
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(Event $event, CancelledBy $by, ?string $reason = null): Event
    {
        return DB::transaction(function () use ($event, $by, $reason): Event {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($event->status !== EventStatus::Published || ! $event->ends_at->isFuture()) {
                throw new ConflictHttpException('Seul un événement publié et pas encore terminé peut être annulé.');
            }

            $event->forceFill([
                'status' => EventStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by,
                'cancellation_reason' => $reason,
            ])->save();

            PendingRevisions::closeFor($event, 'Événement annulé.');
            Attendees::notify($event, EventNotice::Cancelled);

            SecurityLog::record('events.cancelled', ['event' => $event->uuid, 'by' => $by->value]);

            return $event;
        });
    }
}
