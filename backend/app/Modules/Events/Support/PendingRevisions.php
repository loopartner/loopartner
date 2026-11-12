<?php

namespace App\Modules\Events\Support;

use App\Models\Media;
use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Models\EventRevision;
use App\Support\Media\MediaLibrary;

/**
 * Éléments en attente devenus sans objet : l'événement est annulé ou terminé. La révision en
 * attente passe à "superseded" (sa couverture proposée est supprimée), les annonces en attente
 * sont refusées sans email. À appeler dans une transaction.
 */
final class PendingRevisions
{
    public static function closeFor(Event $event, string $announcementReason): void
    {
        $library = app(MediaLibrary::class);

        EventRevision::query()
            ->where('event_id', $event->id)
            ->where('status', RevisionStatus::Pending->value)
            ->get()
            ->each(fn (EventRevision $revision) => self::supersede($revision, $library));

        EventAnnouncement::query()
            ->where('event_id', $event->id)
            ->where('status', AnnouncementStatus::PendingReview->value)
            ->update([
                'status' => AnnouncementStatus::Rejected->value,
                'rejection_reason' => $announcementReason,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public static function supersede(EventRevision $revision, MediaLibrary $library): void
    {
        self::deleteProposedCover($revision, $library);

        $revision->forceFill([
            'status' => RevisionStatus::Superseded,
            'pending_key' => null,
            'decided_at' => now(),
        ])->save();
    }

    /**
     * Supprime la couverture proposée par une révision qui ne sera pas appliquée.
     */
    public static function deleteProposedCover(EventRevision $revision, MediaLibrary $library): void
    {
        if ($revision->cover_media_id === null) {
            return;
        }

        $media = Media::query()->whereKey($revision->cover_media_id)->first();
        $revision->forceFill(['cover_media_id' => null]);

        if ($media !== null) {
            $library->delete($media);
        }
    }
}
