<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\Attendees;
use Illuminate\Support\Collection;

/**
 * Rappel envoyé aux inscrits avant le début d'un événement (config events.reminder_hours_before,
 * 24 heures), par la tâche events:send-reminders (toutes les 15 minutes).
 *
 * Sans doublon : chaque événement est réservé par un UPDATE conditionnel sur reminder_sent_at
 * avant l'envoi ; une exécution concurrente ou relancée ne le renvoie pas. Les inscrits arrivés
 * moins de 24 heures avant le début ne le reçoivent pas : leur confirmation d'inscription suffit.
 */
class SendEventReminders
{
    /**
     * @return int nombre d'événements traités
     */
    public function handle(): int
    {
        $hours = (int) config('events.reminder_hours_before');
        $processed = 0;

        Event::query()
            ->where('status', EventStatus::Published->value)
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours($hours))
            ->chunkById(100, function (Collection $events) use ($hours, &$processed): void {
                foreach ($events as $event) {
                    /** @var Event $event */
                    $claimed = Event::query()
                        ->whereKey($event->id)
                        ->whereNull('reminder_sent_at')
                        ->update(['reminder_sent_at' => now()]);

                    if ($claimed !== 1) {
                        continue;
                    }

                    $cutoff = $event->starts_at->copy()->subHours($hours);

                    Attendees::notify(
                        $event,
                        EventNotice::Reminder,
                        fn (EventRegistration $registration): bool => $registration->created_at !== null && $registration->created_at->lessThanOrEqualTo($cutoff),
                    );
                    $processed++;
                }
            });

        return $processed;
    }
}
