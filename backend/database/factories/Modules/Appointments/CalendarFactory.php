<?php

namespace Database\Factories\Modules\Appointments;

use App\Models\User;
use App\Modules\Appointments\Models\Calendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Calendrier de test : Europe/Paris, réservations ouvertes, créneaux de 30 minutes, sans
 * marge ni délai de prévenance, horizon de 30 jours.
 *
 * @extends Factory<Calendar>
 */
class CalendarFactory extends Factory
{
    protected $model = Calendar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'timezone' => 'Europe/Paris',
            'is_enabled' => true,
            'public_page_enabled' => false,
            'slot_step_minutes' => 30,
            'buffer_minutes' => 0,
            'min_notice_minutes' => 0,
            'horizon_days' => 30,
            'reminder_offsets' => [1440],
        ];
    }
}
