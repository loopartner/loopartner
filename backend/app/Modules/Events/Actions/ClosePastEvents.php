<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Notifications\EventNotification;
use App\Modules\Events\Support\PendingRevisions;
use App\Support\SecurityLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie (tâche events:close-past, chaque heure) :
 *
 * - événements publiés terminés : statut "past" ; leur révision et leurs annonces en attente
 *   deviennent sans objet ;
 * - événements en attente de validation dont la date de début est passée : refusés par la
 *   plateforme, avec un motif, et leur organisateur est prévenu.
 *
 * L'agenda public ne dépend pas de cette tâche : il filtre déjà sur la date de fin.
 */
class ClosePastEvents
{
    public const EXPIRED_REASON = 'La date de début est passée avant la validation de l\'événement.';

    /**
     * @return array{past: int, expired: int}
     */
    public function handle(): array
    {
        $past = 0;
        $expired = 0;

        Event::query()
            ->where('status', EventStatus::Published->value)
            ->where('ends_at', '<=', now())
            ->chunkById(100, function (Collection $events) use (&$past): void {
                foreach ($events as $event) {
                    /** @var Event $event */
                    DB::transaction(function () use ($event): void {
                        $event->forceFill(['status' => EventStatus::Past])->save();
                        PendingRevisions::closeFor($event, 'Événement terminé.');
                    });
                    $past++;
                }
            });

        Event::query()
            ->where('status', EventStatus::PendingReview->value)
            ->where('starts_at', '<=', now())
            ->with('user')
            ->chunkById(100, function (Collection $events) use (&$expired): void {
                foreach ($events as $event) {
                    /** @var Event $event */
                    DB::transaction(function () use ($event): void {
                        $event->forceFill([
                            'status' => EventStatus::Rejected,
                            'rejected_at' => now(),
                            'rejection_reason' => self::EXPIRED_REASON,
                        ])->save();

                        if ($event->user !== null) {
                            EventNotification::sendTo($event->user, $event, EventNotice::EventRejected, self::EXPIRED_REASON);
                        }
                    });

                    SecurityLog::record('events.expired_in_review', ['user_id' => null, 'event' => $event->uuid]);
                    $expired++;
                }
            });

        return ['past' => $past, 'expired' => $expired];
    }
}
