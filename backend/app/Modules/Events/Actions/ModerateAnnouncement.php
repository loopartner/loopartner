<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Notifications\EventNotification;
use App\Support\AdminAudit;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Validation ou refus (avec motif) d'une annonce contenant un lien. Une annonce validée est
 * envoyée aux inscrits ; l'organisateur est prévenu de la décision. Le texte d'une annonce ne
 * change jamais après son envoi : pas de contrôle de version.
 */
class ModerateAnnouncement
{
    /**
     * @throws ConflictHttpException
     */
    public function approve(User $admin, EventAnnouncement $announcement): EventAnnouncement
    {
        return DB::transaction(function () use ($admin, $announcement): EventAnnouncement {
            [$event, $announcement] = $this->lock($announcement);

            if (! $event->isLive()) {
                throw new ConflictHttpException('L\'événement n\'est plus publié ou est terminé : l\'annonce ne peut plus être envoyée.');
            }

            $announcement->forceFill(['decided_at' => now(), 'decided_by' => $admin->id]);
            PostAnnouncement::publish($event, $announcement);

            AdminAudit::record($admin, 'events.announcement.approved', 'announcement', $announcement->uuid);
            SecurityLog::record('events.announcement_approved', ['user_id' => $admin->id, 'announcement' => $announcement->uuid]);

            if ($event->user !== null) {
                EventNotification::sendTo($event->user, $event, EventNotice::AnnouncementApproved);
            }

            return $announcement;
        });
    }

    /**
     * @throws ConflictHttpException
     */
    public function reject(User $admin, EventAnnouncement $announcement, string $reason): EventAnnouncement
    {
        return DB::transaction(function () use ($admin, $announcement, $reason): EventAnnouncement {
            [$event, $announcement] = $this->lock($announcement);

            $announcement->forceFill([
                'status' => AnnouncementStatus::Rejected,
                'decided_at' => now(),
                'decided_by' => $admin->id,
                'rejection_reason' => $reason,
            ])->save();

            AdminAudit::record($admin, 'events.announcement.rejected', 'announcement', $announcement->uuid, null, $reason);
            SecurityLog::record('events.announcement_rejected', ['user_id' => $admin->id, 'announcement' => $announcement->uuid]);

            if ($event->user !== null) {
                EventNotification::sendTo($event->user, $event, EventNotice::AnnouncementRejected, $reason);
            }

            return $announcement;
        });
    }

    /**
     * @return array{0: Event, 1: EventAnnouncement}
     *
     * @throws ConflictHttpException
     */
    private function lock(EventAnnouncement $announcement): array
    {
        $event = Event::query()->whereKey($announcement->event_id)->lockForUpdate()->firstOrFail();
        $announcement = EventAnnouncement::query()->whereKey($announcement->id)->lockForUpdate()->firstOrFail();

        if ($announcement->status !== AnnouncementStatus::PendingReview) {
            throw new ConflictHttpException('Cette annonce n\'est plus en attente de validation.');
        }

        return [$event, $announcement];
    }
}
