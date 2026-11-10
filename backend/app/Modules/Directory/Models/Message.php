<?php

namespace App\Modules\Directory\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Message d'une conversation, en texte brut.
 *
 * Donnée personnelle : son contenu n'apparaît jamais dans les logs ni dans les emails, et
 * l'identifiant de l'expéditeur n'est jamais exposé (MessageResource indique seulement "mine").
 * Un message n'est pas modifiable : il n'a pas de colonne updated_at.
 *
 * @property int $id
 * @property string $uuid
 * @property int $conversation_id
 * @property int|null $sender_id
 * @property string $body
 * @property Carbon|CarbonImmutable|null $created_at
 * @property-read Conversation $conversation
 * @property-read User|null $sender
 */
#[Fillable(['body'])]
class Message extends Model
{
    public const UPDATED_AT = null;

    public const MAX_LENGTH = 5000;

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
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
