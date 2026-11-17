<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Appointments\Enums\TypeVisibility;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\Calendar;
use Illuminate\Database\Eloquent\Collection;

/**
 * Types réservables d'un calendrier : actifs, non masqués par la modération, et parmi les
 * premiers dans l'ordre choisi par le prestataire, dans la limite appointments.active_types de
 * son plan (fin du Premium : seuls les premiers restent réservables, décision 54).
 */
final class BookableTypes
{
    public function __construct(private Plan $plan) {}

    /**
     * @return Collection<int, AppointmentType>
     */
    public function of(Calendar $calendar, bool $publicOnly = false): Collection
    {
        $limit = $this->plan->limit($calendar->user_id, 'active_types');

        $types = AppointmentType::query()
            ->where('calendar_id', $calendar->id)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit((int) $limit))
            ->get()
            ->reject(fn (AppointmentType $type): bool => $type->isRestricted());

        return $publicOnly
            ? $types->filter(fn (AppointmentType $type): bool => $type->visibility === TypeVisibility::Public)->values()
            : $types->values();
    }

    /**
     * @return list<int>
     */
    public function ids(Calendar $calendar): array
    {
        return $this->of($calendar)->modelKeys();
    }
}
