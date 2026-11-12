<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Enums\RegistrationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inscription d'un utilisateur à un événement gratuit. Aucun attribut n'est assignable en masse
 * (RegisterForEvent). Une inscription retirée par l'organisateur (status "removed") est
 * conservée : elle empêche la réinscription.
 *
 * @property int $id
 * @property string $uuid
 * @property int $event_id
 * @property int $user_id
 * @property RegistrationStatus $status
 * @property Carbon|CarbonImmutable|null $removed_at
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 * @property-read Event $event
 * @property-read User $user
 */
class EventRegistration extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
