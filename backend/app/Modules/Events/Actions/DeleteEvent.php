<?php

namespace App\Modules\Events\Actions;

use App\Models\Media;
use App\Modules\Events\Models\Event;
use App\Support\Media\MediaLibrary;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Supprime un brouillon ou un événement refusé (jamais publié : aucun inscrit), avec sa
 * couverture. Un événement publié s'annule (CancelEvent), il ne se supprime pas.
 */
class DeleteEvent
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @throws ConflictHttpException
     */
    public function handle(Event $event): void
    {
        DB::transaction(function () use ($event): void {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if (! $event->status->isEditable()) {
                throw new ConflictHttpException('Seul un brouillon ou un événement refusé peut être supprimé : annulez plutôt un événement publié.');
            }

            $cover = $event->cover_media_id !== null ? Media::query()->whereKey($event->cover_media_id)->first() : null;

            $event->delete();

            if ($cover !== null) {
                $this->library->delete($cover);
            }
        });
    }
}
