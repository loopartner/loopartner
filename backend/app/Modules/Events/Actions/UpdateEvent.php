<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Support\Attendees;
use App\Modules\Events\Support\EventContent;
use App\Support\AdminAudit;
use App\Support\SecurityLog;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Modifie partiellement un événement de son organisateur.
 *
 * - Brouillon ou refusé : modification directe (rien n'est public).
 * - Publié et pas encore commencé : révision en attente de validation (SubmitRevision), sans
 *   jamais toucher à la version publique. Exception : l'admin organisateur dont la session a
 *   passé la 2FA ($adminDirect, vérifié par le contrôleur) modifie directement son événement,
 *   avec une trace d'audit ; les inscrits sont prévenus d'une modification importante.
 * - En attente de validation : 409. L'organisateur retire d'abord sa soumission : un admin ne
 *   valide jamais une version qu'il n'a pas vue.
 * - Commencé, annulé ou passé : 409.
 *
 * @phpstan-type Outcome array{event: Event, revision: EventRevision|null}
 */
class UpdateEvent
{
    public function __construct(
        private ApplyEventChanges $applyEventChanges,
        private SubmitRevision $submitRevision,
    ) {}

    /**
     * @param  array<string, mixed>  $data  données validées (champs présents uniquement)
     * @return Outcome révision créée ou remplacée, null pour une modification directe
     *
     * @throws ConflictHttpException
     * @throws ValidationException
     * @throws ThrottleRequestsException
     */
    public function handle(User $organizer, Event $event, array $data, bool $adminDirect = false): array
    {
        return DB::transaction(function () use ($organizer, $event, $data, $adminDirect): array {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($event->status === EventStatus::PendingReview) {
                throw new ConflictHttpException('Cet événement est en cours de validation : retirez la soumission pour le modifier.');
            }

            if ($event->status->isEditable()) {
                $this->applyEventChanges->handle($organizer, $event, $data, requireComplete: false);

                return ['event' => $event, 'revision' => null];
            }

            if (! $event->isUpcoming()) {
                throw new ConflictHttpException('Cet événement ne peut plus être modifié.');
            }

            if ($adminDirect) {
                $this->updatePublishedByAdmin($organizer, $event, $data);

                return ['event' => $event, 'revision' => null];
            }

            return ['event' => $event, 'revision' => $this->submitRevision->handle($organizer, $event, $data)];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updatePublishedByAdmin(User $admin, Event $event, array $data): void
    {
        $changed = $this->applyEventChanges->handle($admin, $event, $data, requireComplete: true);

        if ($changed === []) {
            return;
        }

        AdminAudit::record($admin, 'events.event.updated_directly', 'event', $event->uuid, $event->version);
        SecurityLog::record('events.updated_directly', ['user_id' => $admin->id, 'event' => $event->uuid, 'changed_fields' => $changed]);

        if (array_intersect($changed, EventContent::IMPORTANT_FIELDS) !== []) {
            Attendees::notify($event, EventNotice::Changed);
        }
    }
}
