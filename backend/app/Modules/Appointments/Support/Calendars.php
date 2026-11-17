<?php

namespace App\Modules\Appointments\Support;

use App\Models\User;
use App\Modules\Appointments\Models\Calendar;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Calendrier d'un prestataire : créé avec les réglages par défaut (config/appointments.php,
 * defaults) au premier besoin, réservations fermées tant qu'il ne les ouvre pas.
 */
final class Calendars
{
    public static function of(User $provider): Calendar
    {
        $calendar = Calendar::query()->where('user_id', $provider->id)->first();

        if ($calendar !== null) {
            return $calendar;
        }

        $calendar = new Calendar(self::defaults());

        try {
            $calendar->forceFill(['user_id' => $provider->id])->save();
        } catch (UniqueConstraintViolationException) {
            return Calendar::query()->where('user_id', $provider->id)->firstOrFail();
        }

        return $calendar;
    }

    /**
     * Calendrier verrouillé jusqu'à la fin de la transaction (à appeler dans une transaction).
     */
    public static function lock(int $calendarId): Calendar
    {
        return Calendar::query()->whereKey($calendarId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'timezone' => (string) config('appointments.default_timezone'),
            'is_enabled' => false,
            'public_page_enabled' => false,
            'slot_step_minutes' => (int) config('appointments.defaults.slot_step_minutes'),
            'buffer_minutes' => (int) config('appointments.defaults.buffer_minutes'),
            'min_notice_minutes' => (int) config('appointments.defaults.min_notice_hours') * 60,
            'horizon_days' => (int) config('appointments.defaults.horizon_days'),
            'reminder_offsets' => array_values(array_map('intval', (array) config('appointments.default_reminder_offsets'))),
        ];
    }
}
