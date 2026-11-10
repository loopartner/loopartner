<?php

namespace App\Modules\Directory\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Conversation à deux participants, ouverte par l'acceptation d'une demande.
 *
 * Aucun attribut n'est assignable en masse : tout est fixé par les Actions. Un participant dont
 * le compte a été supprimé garde sa ligne avec user_id à null : la conversation passe alors en
 * lecture seule et sera purgée (orphaned_at).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $match_request_id
 * @property Carbon|CarbonImmutable|null $last_activity_at
 * @property Carbon|CarbonImmutable|null $orphaned_at
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 * @property-read MatchRequest|null $matchRequest
 * @property-read Collection<int, ConversationParticipant> $participants
 * @property-read Message|null $lastMessage
 */
class Conversation extends Model
{
    /**
     * Délai minimal entre deux emails "nouveau message" pour un même destinataire et une même
     * conversation.
     */
    public const EMAIL_COOLDOWN_MINUTES = 15;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'orphaned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MatchRequest, $this>
     */
    public function matchRequest(): BelongsTo
    {
        return $this->belongsTo(MatchRequest::class);
    }

    /**
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasOne<Message, $this>
     */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('id');
    }

    /**
     * Ligne de participation de l'utilisateur (null s'il ne participe pas).
     */
    public function participantFor(User $user): ?ConversationParticipant
    {
        return $this->participants->first(fn (ConversationParticipant $participant): bool => $participant->user_id === $user->id);
    }

    /**
     * Ligne de l'autre participant (son user_id est null si son compte a été supprimé).
     */
    public function otherParticipant(User $user): ?ConversationParticipant
    {
        return $this->participants->first(fn (ConversationParticipant $participant): bool => $participant->user_id !== $user->id);
    }

    public function hasParticipant(User $user): bool
    {
        return $this->participantFor($user) !== null;
    }

    /**
     * Conversations dont l'utilisateur est participant.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forUser(Builder $query, User $user): void
    {
        $query->whereHas('participants', fn (Builder $participants) => $participants->where('user_id', $user->id));
    }
}
