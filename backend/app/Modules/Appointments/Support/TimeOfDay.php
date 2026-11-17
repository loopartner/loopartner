<?php

namespace App\Modules\Appointments\Support;

/**
 * Heure du jour saisie "HH:MM" (minutes multiples de 5, "24:00" accepté pour une fin) et
 * minutes depuis minuit.
 */
final class TimeOfDay
{
    public const PATTERN = '/^(?:(?:[01]\d|2[0-3]):[0-5][05]|24:00)$/';

    public static function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    public static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
