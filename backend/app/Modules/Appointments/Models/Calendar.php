<?php

namespace App\Modules\Appointments\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Appointments\CalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calendrier de rendez-vous d'un prestataire (une ligne par prestataire) : fuseau, ouverture
 * des réservations, page publique, pas des créneaux, marge entre deux rendez-vous, délai de
 * prévenance, horizon et délais de rappel choisis.
 *
 * Sa ligne est verrouillée pendant chaque réservation, report ou confirmation : toutes les
 * opérations qui retiennent un créneau de ce prestataire passent l'une après l'autre.
 *
 * @property int $id
 * @property int $user_id
 * @property string $timezone
 * @property bool $is_enabled
 * @property bool $public_page_enabled
 * @property int $slot_step_minutes
 * @property int $buffer_minutes
 * @property int $min_notice_minutes
 * @property int $horizon_days
 * @property list<int>|null $reminder_offsets
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, AppointmentType> $types
 * @property-read Collection<int, AvailabilityRule> $rules
 * @property-read Collection<int, AvailabilityException> $exceptions
 */
#[UseFactory(CalendarFactory::class)]
#[Fillable(['timezone', 'is_enabled', 'public_page_enabled', 'slot_step_minutes', 'buffer_minutes', 'min_notice_minutes', 'horizon_days', 'reminder_offsets'])]
class Calendar extends Model
{
    /** @use HasFactory<CalendarFactory> */
    use HasFactory;

    protected $table = 'appointment_calendars';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'public_page_enabled' => 'boolean',
            'slot_step_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'min_notice_minutes' => 'integer',
            'horizon_days' => 'integer',
            'reminder_offsets' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AppointmentType, $this>
     */
    public function types(): HasMany
    {
        return $this->hasMany(AppointmentType::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<AvailabilityRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class)->orderBy('weekday')->orderBy('start_minute');
    }

    /**
     * @return HasMany<AvailabilityException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class)->orderBy('date')->orderBy('start_minute');
    }
}
