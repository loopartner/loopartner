<?php

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Support\Agenda\AgendaItem;
use App\Support\Agenda\AgendaSource;
use Carbon\CarbonImmutable;

/**
 * Événements dans l'agenda unifié (lot 4), sans rien changer au module Events :
 *
 * - ceux auxquels l'utilisateur est inscrit (inscription active) : publiés, passés ou annulés
 *   dans l'agenda ; publiés ou passés dans l'abonnement ;
 * - ceux qu'il organise : publiés ou passés.
 *
 * Un événement masqué par la modération n'apparaît pas. Titre : celui de l'événement
 * (public) ; jamais le lieu ni le lien de connexion.
 */
class EventsAgendaSource implements AgendaSource
{
    public function items(User $user, CarbonImmutable $from, CarbonImmutable $to, string $locale, bool $forFeed): array
    {
        $visible = [EventStatus::Published->value, EventStatus::Past->value];
        $registeredStatuses = $forFeed ? $visible : [...$visible, EventStatus::Cancelled->value];

        $registered = Event::query()
            ->whereHas('registrations', fn ($query) => $query->where('user_id', $user->id)->where('status', RegistrationStatus::Registered->value))
            ->whereIn('status', $registeredStatuses)
            ->notRestricted()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->limit(500)
            ->get()
            ->map(fn (Event $event): AgendaItem => self::item($event, 'event_registration', 'participant'));

        $organized = Event::query()
            ->where('user_id', $user->id)
            ->whereIn('status', $visible)
            ->notRestricted()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->limit(500)
            ->get()
            ->map(fn (Event $event): AgendaItem => self::item($event, 'event_organized', 'organizer'));

        return $registered->concat($organized)->values()->all();
    }

    public function disclaimer(string $locale): ?array
    {
        return null;
    }

    private static function item(Event $event, string $kind, string $role): AgendaItem
    {
        return new AgendaItem(
            source: 'events',
            kind: $kind,
            ref: $event->slug ?? $event->uuid,
            title: $event->title,
            startsAt: CarbonImmutable::parse($event->starts_at)->utc(),
            endsAt: CarbonImmutable::parse($event->ends_at)->utc(),
            allDay: false,
            timezone: $event->timezone,
            status: $event->status->value,
            role: $role,
        );
    }
}
