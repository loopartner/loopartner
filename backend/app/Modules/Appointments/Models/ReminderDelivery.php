<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Appointments\Enums\Party;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Rappel d'un rendez-vous envoyé à une partie, à un délai : réservé avant l'envoi (index
 * unique), jamais envoyé deux fois. Supprimé quand le rendez-vous est déplacé (les rappels
 * repartent pour le nouveau créneau), et purgé après 3 mois.
 *
 * @property int $id
 * @property int $appointment_id
 * @property Party $recipient_side
 * @property int $offset_minutes
 * @property CarbonInterface $sent_at
 */
class ReminderDelivery extends Model
{
    public $timestamps = false;

    protected $table = 'appointment_reminders';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_side' => Party::class,
            'offset_minutes' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
