<?php

namespace App\Modules\Appointments\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Plage hebdomadaire de disponibilité, en heure locale du calendrier : jour ISO (1 = lundi,
 * 7 = dimanche), minutes depuis minuit (fin à 1440 au plus). Remplacées en bloc par
 * ReplaceAvailabilityRules.
 *
 * @property int $id
 * @property int $calendar_id
 * @property int $weekday
 * @property int $start_minute
 * @property int $end_minute
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AvailabilityRule extends Model
{
    protected $table = 'appointment_availability_rules';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'start_minute' => 'integer',
            'end_minute' => 'integer',
        ];
    }
}
