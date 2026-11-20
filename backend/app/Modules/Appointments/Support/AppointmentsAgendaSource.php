<?php

namespace App\Modules\Appointments\Support;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Support\Agenda\AgendaItem;
use App\Support\Agenda\AgendaSource;
use Carbon\CarbonImmutable;

/**
 * Rendez-vous dans l'agenda unifié : ceux de l'utilisateur comme client ou comme prestataire.
 * Agenda : demandes, propositions et rendez-vous confirmés ; abonnement : confirmés seulement.
 * Titre : « Rendez-vous : <type> » (jamais de nom, de lieu ni de note).
 */
class AppointmentsAgendaSource implements AgendaSource
{
    public function items(User $user, CarbonImmutable $from, CarbonImmutable $to, string $locale, bool $forFeed): array
    {
        return Appointment::query()
            ->where(fn ($query) => $query->where('client_id', $user->id)->orWhere('provider_id', $user->id))
            ->whereIn('status', $forFeed ? [AppointmentStatus::Confirmed->value] : AppointmentStatus::activeValues())
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with('type')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (Appointment $appointment): AgendaItem => new AgendaItem(
                source: 'appointments',
                kind: 'appointment',
                ref: $appointment->uuid,
                title: AppointmentIcs::title($appointment, $locale),
                startsAt: CarbonImmutable::parse($appointment->starts_at)->utc(),
                endsAt: CarbonImmutable::parse($appointment->ends_at)->utc(),
                allDay: false,
                timezone: $appointment->timezone,
                status: $appointment->status->value,
                role: $appointment->partyOf($user)?->value,
            ))
            ->values()
            ->all();
    }

    public function disclaimer(string $locale): ?array
    {
        return null;
    }
}
