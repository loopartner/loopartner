<?php

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Notifications\EventNotification;
use Illuminate\Support\Collection;

/**
 * Prévient les inscrits actifs d'un événement (annulation, modification, annonce, rappel), par
 * paquets, chacun dans sa langue.
 */
final class Attendees
{
    /**
     * @param  (callable(EventRegistration): bool)|null  $filter  inscriptions à prévenir
     * @return int nombre d'inscrits prévenus
     */
    public static function notify(Event $event, EventNotice $notice, ?callable $filter = null): int
    {
        $sent = 0;

        EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', RegistrationStatus::Registered->value)
            ->with('user')
            ->chunkById(200, function (Collection $registrations) use ($event, $notice, $filter, &$sent): void {
                foreach ($registrations as $registration) {
                    /** @var EventRegistration $registration */
                    if ($filter !== null && ! $filter($registration)) {
                        continue;
                    }

                    EventNotification::sendTo($registration->user, $event, $notice);
                    $sent++;
                }
            });

        return $sent;
    }
}
