<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Support\PendingRevisions;
use App\Support\Media\MediaLibrary;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Retire la révision en attente d'un événement : la version publiée reste inchangée et la
 * couverture proposée est supprimée.
 */
class WithdrawRevision
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @throws ConflictHttpException
     */
    public function handle(Event $event): void
    {
        DB::transaction(function () use ($event): void {
            $revision = EventRevision::query()
                ->where('event_id', $event->id)
                ->where('status', RevisionStatus::Pending->value)
                ->lockForUpdate()
                ->first();

            if ($revision === null) {
                throw new ConflictHttpException('Aucune modification n\'est en attente de validation pour cet événement.');
            }

            PendingRevisions::supersede($revision, $this->library);

            SecurityLog::record('events.revision_withdrawn', ['event' => $event->uuid, 'revision' => $revision->uuid]);
        });
    }
}
