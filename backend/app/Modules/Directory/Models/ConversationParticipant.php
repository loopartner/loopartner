<?php

namespace App\Modules\Directory\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Participation d'un utilisateur à une conversation : marqueur de lecture et date du dernier
 * email de notification. Aucun attribut n'est assignable en masse.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|null $user_id
 * @property int|null $last_read_message_id
 * @property Carbon|CarbonImmutable|null $last_notified_at
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 * @property-read Conversation $conversation
 * @property-read User|null $user
 */
class ConversationParticipant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_message_id' => 'integer',
            'last_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
