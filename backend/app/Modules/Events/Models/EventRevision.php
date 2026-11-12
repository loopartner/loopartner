<?php

namespace App\Modules\Events\Models;

use App\Models\Media;
use App\Models\User;
use App\Modules\Events\Enums\RevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Révision d'un événement publié : nouvelle version proposée par l'organisateur, appliquée
 * seulement après la validation d'un admin. Le public continue de voir la version validée.
 *
 * payload contient la version complète proposée (voir EventContent), plus l'opération sur la
 * couverture ("cover" : keep, remove ou replace avec cover_media_id). Il est chiffré en base :
 * il contient le lien de connexion. Aucun attribut n'est assignable en masse (SubmitRevision).
 *
 * @property int $id
 * @property string $uuid
 * @property int $event_id
 * @property int $user_id
 * @property RevisionStatus $status
 * @property int $version
 * @property array<string, mixed> $payload
 * @property list<string> $changed_fields
 * @property int|null $cover_media_id
 * @property int|null $pending_key
 * @property Carbon|CarbonImmutable $submitted_at
 * @property Carbon|CarbonImmutable|null $decided_at
 * @property int|null $decided_by
 * @property string|null $rejection_reason
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 * @property-read Event $event
 */
class EventRevision extends Model
{
    public const COVER_KEEP = 'keep';

    public const COVER_REMOVE = 'remove';

    public const COVER_REPLACE = 'replace';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RevisionStatus::class,
            'payload' => 'encrypted:array',
            'changed_fields' => 'array',
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
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

    /**
     * Nouvelle couverture proposée (operation "replace").
     *
     * @return BelongsTo<Media, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function isPending(): bool
    {
        return $this->status === RevisionStatus::Pending;
    }

    /**
     * Opération proposée sur la couverture : keep, remove ou replace.
     */
    public function coverOperation(): string
    {
        $operation = $this->payload['cover'] ?? self::COVER_KEEP;

        return is_string($operation) ? $operation : self::COVER_KEEP;
    }
}
