<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Appointments\Enums\HoldKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Créneau retenu par un rendez-vous actif (current) ou par un report proposé (proposed).
 * slot_key (calendrier et début UTC) est unique : seconde barrière contre la double
 * réservation, après le verrou du calendrier. Supprimé dès que le créneau est libéré.
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $calendar_id
 * @property HoldKind $kind
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property string $slot_key
 */
class SlotHold extends Model
{
    public $timestamps = false;

    protected $table = 'appointment_slot_holds';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => HoldKind::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public static function keyFor(int $calendarId, CarbonInterface $startsAt): string
    {
        return $calendarId.':'.$startsAt->copy()->utc()->format('Y-m-d\TH:i');
    }
}
