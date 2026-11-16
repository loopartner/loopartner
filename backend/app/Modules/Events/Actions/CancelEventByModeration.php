<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\CancelledBy;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Notifications\EventNotification;
use App\Support\AdminAudit;
use App\Support\Moderation\ContentModerated;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Annulation d'un événement publié par un admin (abus, arnaque…), avec un motif obligatoire :
 * les inscrits sont prévenus (CancelEvent), l'organisateur reçoit le motif, et la décision est
 * tracée dans le journal d'audit. L'événement disparaît de l'agenda public (404).
 */
class CancelEventByModeration
{
    public function __construct(private CancelEvent $cancelEvent) {}

    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, Event $event, string $reason): Event
    {
        return DB::transaction(function () use ($admin, $event, $reason): Event {
            $event = $this->cancelEvent->handle($event, CancelledBy::Admin, $reason);

            AdminAudit::record($admin, 'events.event.cancelled', 'event', $event->uuid, $event->version, $reason);
            // Exposé des motifs consultable et contestable (module Moderation).
            ContentModerated::dispatch('event', $event->slug ?? $event->uuid, $event->user_id, $event->title, $reason, $admin->id, ContentModerated::WITHDRAWN);

            if ($event->user !== null) {
                EventNotification::sendTo($event->user, $event, EventNotice::CancelledByModeration, $reason);
            }

            return $event;
        });
    }
}
