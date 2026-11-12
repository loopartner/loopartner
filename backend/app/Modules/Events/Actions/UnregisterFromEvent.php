<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Désinscription volontaire, avant le début de l'événement : l'inscription est supprimée et
 * la place libérée.
 */
class UnregisterFromEvent
{
    /**
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     */
    public function handle(User $user, Event $event): void
    {
        DB::transaction(function () use ($user, $event): void {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $registration = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('user_id', $user->id)
                ->where('status', RegistrationStatus::Registered->value)
                ->lockForUpdate()
                ->first();

            if ($registration === null) {
                throw new NotFoundHttpException('Inscription introuvable.');
            }

            if (! $event->isUpcoming()) {
                throw new ConflictHttpException('L\'événement a commencé ou n\'est plus publié : la désinscription n\'est plus possible.');
            }

            $registration->delete();
            $event->forceFill(['registrations_count' => max(0, $event->registrations_count - 1)])->save();
        });
    }
}
