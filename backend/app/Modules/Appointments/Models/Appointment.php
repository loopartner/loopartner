<?php

namespace App\Modules\Appointments\Models;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentOrigin;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\CancellationReason;
use App\Modules\Appointments\Enums\CancelledBy;
use App\Modules\Appointments\Enums\LocationMode;
use App\Modules\Appointments\Enums\Party;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rendez-vous entre un prestataire et un client (porteur de projet), dans une demande de mise
 * en relation (décision 52).
 *
 * - Visible de ses deux parties seulement : tout autre utilisateur reçoit 404, admins compris.
 * - Le type est copié à la réservation (titre, durée, mode de lieu) ; le lieu (adresse,
 *   téléphone, lien de visio) est chiffré, montré à l'autre partie une fois confirmé, et
 *   effacé après la durée de conservation (décision 57).
 * - change_* : report proposé par une partie, en attente de la réponse de l'autre.
 * - Aucun champ assignable : tout est fixé par les Actions (forceFill).
 *
 * @property int $id
 * @property string $uuid
 * @property int $match_request_id
 * @property int|null $calendar_id
 * @property int|null $type_id
 * @property int|null $provider_id
 * @property int|null $client_id
 * @property AppointmentOrigin $origin
 * @property Party $created_by
 * @property AppointmentStatus $status
 * @property string $type_title
 * @property int $duration_minutes
 * @property LocationMode $location_mode
 * @property \ArrayObject<string, string>|null $location
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property string $timezone
 * @property int $version
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $scheduled_at
 * @property CarbonInterface|null $confirmed_at
 * @property CarbonInterface|null $declined_at
 * @property CarbonInterface|null $cancelled_at
 * @property CancelledBy|null $cancelled_by
 * @property CancellationReason|null $cancellation_reason
 * @property CarbonInterface|null $expired_at
 * @property int $reschedule_count
 * @property CarbonInterface|null $change_starts_at
 * @property CarbonInterface|null $change_ends_at
 * @property Party|null $change_by
 * @property CarbonInterface|null $change_expires_at
 * @property CarbonInterface|null $location_erased_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read AppointmentType|null $type
 * @property-read User|null $provider
 * @property-read User|null $client
 * @property-read Calendar|null $calendar
 * @property-read Collection<int, AppointmentNote> $notes
 */
class Appointment extends Model
{
    protected $table = 'appointments';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
        'reschedule_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => AppointmentOrigin::class,
            'created_by' => Party::class,
            'status' => AppointmentStatus::class,
            'duration_minutes' => 'integer',
            'location_mode' => LocationMode::class,
            'location' => AsEncryptedArrayObject::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'version' => 'integer',
            'expires_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'declined_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by' => CancelledBy::class,
            'cancellation_reason' => CancellationReason::class,
            'expired_at' => 'datetime',
            'reschedule_count' => 'integer',
            'change_starts_at' => 'datetime',
            'change_ends_at' => 'datetime',
            'change_by' => Party::class,
            'change_expires_at' => 'datetime',
            'location_erased_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppointmentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class, 'type_id');
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * @return HasMany<AppointmentNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(AppointmentNote::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Partie que représente cet utilisateur, ou null s'il n'y participe pas.
     */
    public function partyOf(User $user): ?Party
    {
        return match (true) {
            $this->provider_id !== null && $this->provider_id === $user->id => Party::Provider,
            $this->client_id !== null && $this->client_id === $user->id => Party::Client,
            default => null,
        };
    }

    /**
     * Partie qui doit répondre à une demande ou à une proposition en attente.
     */
    public function awaitedParty(): ?Party
    {
        return match ($this->status) {
            AppointmentStatus::Requested => Party::Provider,
            AppointmentStatus::Proposed => Party::Client,
            default => null,
        };
    }

    public function hasStarted(): bool
    {
        return ! $this->starts_at->isFuture();
    }

    public function hasPendingChange(): bool
    {
        return $this->change_starts_at !== null;
    }

    public function userId(Party $party): ?int
    {
        return $party === Party::Provider ? $this->provider_id : $this->client_id;
    }
}
