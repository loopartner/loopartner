<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Appointments\Enums\NoteKind;
use App\Modules\Appointments\Enums\Party;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Texte libre d'un rendez-vous (motif ou note de réservation, message d'annulation, de refus
 * ou de report), chiffré en base, lisible par les deux parties et signalable par l'autre
 * partie (adaptateur appointment_note). Masqué, il garde sa place sans son texte ; effacé
 * (body null, erased_at) après la durée de conservation (décision 57).
 *
 * @property int $id
 * @property string $uuid
 * @property int $appointment_id
 * @property int|null $author_id
 * @property Party $author_side
 * @property NoteKind $kind
 * @property string|null $body
 * @property CarbonInterface|null $erased_at
 * @property CarbonInterface|null $moderation_restricted_at
 * @property string|null $moderation_decision_uuid
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Appointment $appointment
 */
class AppointmentNote extends Model
{
    protected $table = 'appointment_notes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_side' => Party::class,
            'kind' => NoteKind::class,
            'body' => 'encrypted',
            'erased_at' => 'datetime',
            'moderation_restricted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
