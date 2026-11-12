<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Notifications\EventNotification;
use App\Modules\Events\Support\EventPublisher;
use App\Modules\Events\Support\Moderation;
use App\Modules\Events\Support\OrganizerQuota;
use App\Support\AdminAudit;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Validation d'un événement en attente par un admin : il est publié (slug calculé à la
 * première publication) et l'organisateur est prévenu.
 *
 * 409 si l'événement n'est plus en attente, a changé depuis la lecture de l'admin (version), a
 * déjà commencé, ou si l'organisateur ne peut plus publier (rôle, profil, plafond).
 */
class ApproveEvent
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, Event $event, int $version): Event
    {
        return DB::transaction(function () use ($admin, $event, $version): Event {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($event->status !== EventStatus::PendingReview) {
                throw new ConflictHttpException('Cet événement n\'est plus en attente de validation.');
            }

            Moderation::assertVersion($version, $event->version);

            if (! $event->starts_at->isFuture()) {
                throw new ConflictHttpException('La date de début de cet événement est passée : il ne peut plus être publié.');
            }

            $organizer = Moderation::assertOrganizerEligible($event->user);
            OrganizerQuota::lock($organizer->id);
            OrganizerQuota::assertCanPublish($organizer->id);

            EventPublisher::publish($event);

            AdminAudit::record($admin, 'events.event.approved', 'event', $event->uuid, $event->version);
            SecurityLog::record('events.approved', ['user_id' => $admin->id, 'event' => $event->uuid, 'version' => $event->version]);

            EventNotification::sendTo($organizer, $event, EventNotice::EventApproved);

            return $event;
        });
    }
}
