<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Notifications\EventNotification;
use App\Support\AdminAudit;
use App\Support\Moderation\VersionGuard;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Refus d'un événement en attente par un admin, avec un motif obligatoire communiqué à
 * l'organisateur (API et email). L'organisateur peut le corriger et le soumettre à nouveau.
 */
class RejectEvent
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, Event $event, int $version, string $reason): Event
    {
        return DB::transaction(function () use ($admin, $event, $version, $reason): Event {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($event->status !== EventStatus::PendingReview) {
                throw new ConflictHttpException('Cet événement n\'est plus en attente de validation.');
            }

            VersionGuard::assertSame($version, $event->version);

            $event->forceFill([
                'status' => EventStatus::Rejected,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            AdminAudit::record($admin, 'events.event.rejected', 'event', $event->uuid, $event->version, $reason);
            SecurityLog::record('events.rejected', ['user_id' => $admin->id, 'event' => $event->uuid, 'version' => $event->version]);

            if ($event->user !== null) {
                EventNotification::sendTo($event->user, $event, EventNotice::EventRejected, $reason);
            }

            return $event;
        });
    }
}
