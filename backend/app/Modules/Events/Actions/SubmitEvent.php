<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\EventContent;
use App\Modules\Events\Support\EventPublisher;
use App\Modules\Events\Support\OrganizerQuota;
use App\Modules\Events\Support\Organizers;
use App\Support\AdminAudit;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Soumet un brouillon ou un événement refusé à la validation d'un admin (pending_review).
 *
 * - L'événement doit être complet (EventContent::assertComplete) : 422 sinon.
 * - Plafonds : config events.max_pending_review_per_organizer et
 *   max_upcoming_published_per_organizer (409), comptés sous verrou de la ligne users.
 * - $publishDirectly (organisateur admin dont la session a passé la 2FA, vérifié par le
 *   contrôleur) : l'événement est publié sans validation, et la décision est tracée dans le
 *   journal d'audit comme celle d'un admin.
 */
class SubmitEvent
{
    /**
     * @throws ConflictHttpException
     * @throws ValidationException
     */
    public function handle(User $organizer, Event $event, bool $publishDirectly): Event
    {
        return DB::transaction(function () use ($organizer, $event, $publishDirectly): Event {
            OrganizerQuota::lock($organizer->id);
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($event->status === EventStatus::PendingReview) {
                throw new ConflictHttpException('Cet événement est déjà en attente de validation.');
            }

            if (! $event->status->isEditable()) {
                throw new ConflictHttpException('Cet événement a déjà été publié.');
            }

            EventContent::assertComplete(EventContent::of($event), Organizers::isProvider($organizer));

            if ($publishDirectly) {
                OrganizerQuota::assertCanPublish($organizer->id);
                $event->forceFill(['submitted_at' => now()]);
                EventPublisher::publish($event);

                AdminAudit::record($organizer, 'events.event.published_directly', 'event', $event->uuid, $event->version);
                SecurityLog::record('events.published_directly', ['user_id' => $organizer->id, 'event' => $event->uuid]);

                return $event;
            }

            OrganizerQuota::assertCanSubmit($organizer->id);

            $event->forceFill([
                'status' => EventStatus::PendingReview,
                'submitted_at' => now(),
            ])->save();

            SecurityLog::record('events.submitted', ['user_id' => $organizer->id, 'event' => $event->uuid]);

            return $event;
        });
    }
}
