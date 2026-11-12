<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Notifications\EventNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Inscrit un utilisateur à un événement gratuit, publié et pas encore commencé, puis lui
 * envoie une confirmation (sans le lien de connexion).
 *
 * La capacité est respectée même en cas d'inscriptions simultanées : la ligne de l'événement
 * est verrouillée pendant le comptage, et l'index unique (event_id, user_id) empêche une double
 * inscription. Pas de liste d'attente : complet donne 409.
 *
 * L'organisateur lui-même et un utilisateur bloqué sont refusés par EventPolicy::register (403).
 */
class RegisterForEvent
{
    /**
     * @throws ConflictHttpException
     * @throws AccessDeniedHttpException
     */
    public function handle(User $user, Event $event): EventRegistration
    {
        try {
            return DB::transaction(function () use ($user, $event): EventRegistration {
                $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                if (! $event->is_free) {
                    throw new ConflictHttpException('Cet événement payant se réserve sur la billetterie de l\'organisateur.');
                }

                if (! $event->isUpcoming()) {
                    throw new ConflictHttpException('Les inscriptions sont closes : l\'événement a commencé ou n\'est plus publié.');
                }

                $existing = EventRegistration::query()
                    ->where('event_id', $event->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing?->status === RegistrationStatus::Removed) {
                    throw new AccessDeniedHttpException('Vous ne pouvez plus vous inscrire à cet événement.');
                }

                if ($existing !== null) {
                    throw new ConflictHttpException('Vous êtes déjà inscrit à cet événement.');
                }

                if ($event->isFull()) {
                    throw new ConflictHttpException('Cet événement est complet.');
                }

                $registration = new EventRegistration;
                $registration->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'status' => RegistrationStatus::Registered,
                ])->save();

                $event->forceFill(['registrations_count' => $event->registrations_count + 1])->save();

                EventNotification::sendTo($user, $event, EventNotice::RegistrationConfirmed);

                return $registration->setRelation('event', $event);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Vous êtes déjà inscrit à cet événement.');
        }
    }
}
