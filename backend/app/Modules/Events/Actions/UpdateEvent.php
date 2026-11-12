<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Modifie partiellement un événement de son organisateur.
 *
 * - Brouillon ou refusé : modification directe (rien n'est public).
 * - En attente de validation : 409. L'organisateur retire d'abord sa soumission : un admin ne
 *   valide jamais une version qu'il n'a pas vue.
 * - Annulé ou passé : 409.
 */
class UpdateEvent
{
    public function __construct(private ApplyEventChanges $applyEventChanges) {}

    /**
     * @param  array<string, mixed>  $data  données validées (champs présents uniquement)
     *
     * @throws ConflictHttpException
     * @throws ValidationException
     */
    public function handle(User $organizer, Event $event, array $data): Event
    {
        return DB::transaction(function () use ($organizer, $event, $data): Event {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($event->status === EventStatus::PendingReview) {
                throw new ConflictHttpException('Cet événement est en cours de validation : retirez la soumission pour le modifier.');
            }

            if (! $event->status->isEditable()) {
                throw new ConflictHttpException('Cet événement ne peut plus être modifié.');
            }

            $this->applyEventChanges->handle($organizer, $event, $data, requireComplete: false);

            return $event;
        });
    }
}
