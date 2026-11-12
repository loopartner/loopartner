<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Notifications\EventNotification;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Retrait d'un inscrit par l'organisateur (comportement abusif, par exemple), sans motif.
 *
 * L'inscription passe au statut "removed" et reste en base : l'inscrit ne peut plus se
 * réinscrire à cet événement. Il reçoit un email neutre (« votre inscription a été annulée par
 * l'organisateur »). Le journal security ne contient que les uuid.
 */
class RemoveRegistrant
{
    /**
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     */
    public function handle(Event $event, string $registrationUuid): void
    {
        DB::transaction(function () use ($event, $registrationUuid): void {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $registration = EventRegistration::query()
                ->where('uuid', $registrationUuid)
                ->where('event_id', $event->id)
                ->where('status', RegistrationStatus::Registered->value)
                ->with('user')
                ->lockForUpdate()
                ->first();

            if ($registration === null) {
                throw new NotFoundHttpException('Inscription introuvable.');
            }

            if ($event->status !== EventStatus::Published || ! $event->ends_at->isFuture()) {
                throw new ConflictHttpException('Seuls les inscrits d\'un événement publié et pas encore terminé peuvent être retirés.');
            }

            $registration->forceFill([
                'status' => RegistrationStatus::Removed,
                'removed_at' => now(),
            ])->save();

            $event->forceFill(['registrations_count' => max(0, $event->registrations_count - 1)])->save();

            EventNotification::sendTo($registration->user, $event, EventNotice::RegistrationRemoved);

            SecurityLog::record('events.registration_removed', ['event' => $event->uuid, 'registration' => $registration->uuid]);
        });
    }
}
