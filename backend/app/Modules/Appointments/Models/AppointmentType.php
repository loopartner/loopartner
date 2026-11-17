<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Appointments\Enums\LocationMode;
use App\Modules\Appointments\Enums\TypeVisibility;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Appointments\AppointmentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Type de rendez-vous d'un prestataire : titre, description, durée, lieu, visibilité
 * (relation ou page publique), confirmation automatique, actif ou non, position.
 *
 * - location_details (chiffré) : adresse du prestataire ou lien de visio, montré au client
 *   seulement une fois un rendez-vous confirmé.
 * - Signalable (adaptateur appointment_type) : masqué, il disparaît de la page publique et
 *   n'est plus réservable ; les rendez-vous déjà pris gardent leur copie du type.
 *
 * @property int $id
 * @property string $uuid
 * @property int $calendar_id
 * @property string $title
 * @property string|null $description
 * @property int $duration_minutes
 * @property LocationMode $location_mode
 * @property string|null $location_details
 * @property TypeVisibility $visibility
 * @property bool $auto_confirm
 * @property bool $is_active
 * @property int $position
 * @property CarbonInterface|null $moderation_restricted_at
 * @property string|null $moderation_decision_uuid
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Calendar $calendar
 */
#[UseFactory(AppointmentTypeFactory::class)]
#[Fillable(['title', 'description', 'duration_minutes', 'location_mode', 'location_details', 'visibility', 'auto_confirm', 'is_active', 'position'])]
class AppointmentType extends Model
{
    /** @use HasFactory<AppointmentTypeFactory> */
    use HasFactory;

    protected $table = 'appointment_types';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visibility' => 'relation',
        'auto_confirm' => false,
        'is_active' => true,
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'location_mode' => LocationMode::class,
            'location_details' => 'encrypted',
            'visibility' => TypeVisibility::class,
            'auto_confirm' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
            'moderation_restricted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function isRestricted(): bool
    {
        return $this->moderation_restricted_at !== null;
    }
}
