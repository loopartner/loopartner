<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Notifications\EventNotification;
use App\Modules\Events\Support\PendingRevisions;
use App\Support\AdminAudit;
use App\Support\Media\MediaLibrary;
use App\Support\Moderation\ContentModerated;
use App\Support\Moderation\VersionGuard;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Refus d'une révision par un admin, avec un motif obligatoire communiqué à l'organisateur. La
 * version publiée reste affichée ; la couverture proposée est supprimée.
 */
class RejectRevision
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, EventRevision $revision, int $version, string $reason): EventRevision
    {
        return DB::transaction(function () use ($admin, $revision, $version, $reason): EventRevision {
            $revision = EventRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();

            if (! $revision->isPending()) {
                throw new ConflictHttpException('Cette modification n\'est plus en attente de validation.');
            }

            VersionGuard::assertSame($version, $revision->version);

            PendingRevisions::deleteProposedCover($revision, $this->library);

            $revision->forceFill([
                'status' => RevisionStatus::Rejected,
                'pending_key' => null,
                'decided_at' => now(),
                'decided_by' => $admin->id,
                'rejection_reason' => $reason,
            ])->save();

            AdminAudit::record($admin, 'events.revision.rejected', 'revision', $revision->uuid, $revision->version, $reason);
            // Exposé des motifs consultable et contestable (module Moderation).
            ContentModerated::dispatch('event', $revision->event->slug ?? $revision->event->uuid, $revision->event->user_id, $revision->event->title, $reason, $admin->id, ContentModerated::REFUSED);
            SecurityLog::record('events.revision_rejected', ['user_id' => $admin->id, 'event' => $revision->event->uuid, 'revision' => $revision->uuid]);

            if ($revision->event->user !== null) {
                EventNotification::sendTo($revision->event->user, $revision->event, EventNotice::RevisionRejected, $reason);
            }

            return $revision;
        });
    }
}
