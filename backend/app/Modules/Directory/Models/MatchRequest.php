<?php

namespace App\Modules\Directory\Models;

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Directory\MatchRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Demande de mise en relation : proposition d'un prestataire sur un projet, ou contact d'un
 * porteur de projet vers un prestataire.
 *
 * - uuid est le seul identifiant exposé par l'API.
 * - Seuls le message et l'estimation de prix sont assignables en masse. Le type, les
 *   participants, le projet, le statut, active_key et les dates sont fixés par les Actions.
 * - author_id ou recipient_id valent null quand le compte correspondant a été supprimé (seules
 *   les demandes acceptées survivent à la suppression d'un compte).
 *
 * @property int $id
 * @property string $uuid
 * @property MatchRequestType $type
 * @property int|null $author_id
 * @property int|null $recipient_id
 * @property int|null $project_id
 * @property string $message
 * @property int|null $price_amount
 * @property string|null $price_currency
 * @property MatchRequestStatus $status
 * @property string|null $active_key
 * @property Carbon|CarbonImmutable|null $expires_at
 * @property Carbon|CarbonImmutable|null $responded_at
 * @property Carbon|CarbonImmutable|null $withdrawn_at
 * @property Carbon|CarbonImmutable|null $expired_at
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 * @property-read User|null $author
 * @property-read User|null $recipient
 * @property-read Project|null $project
 * @property-read Conversation|null $conversation
 */
#[UseFactory(MatchRequestFactory::class)]
#[Fillable(['message', 'price_amount', 'price_currency'])]
class MatchRequest extends Model
{
    /** @use HasFactory<MatchRequestFactory> */
    use HasFactory;

    /**
     * Une demande en attente expire après ce nombre de jours.
     */
    public const EXPIRATION_DAYS = 30;

    /**
     * Nombre maximal de demandes envoyées en attente en même temps, par utilisateur.
     */
    public const MAX_PENDING_SENT = 30;

    public const MESSAGE_MIN_LENGTH = 20;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MatchRequestType::class,
            'status' => MatchRequestStatus::class,
            'price_amount' => 'integer',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /**
     * Clé d'unicité d'une demande active : un auteur n'a qu'une demande active par projet
     * (proposition) ou par prestataire (contact).
     */
    public static function activeKey(MatchRequestType $type, int $authorId, int $targetId): string
    {
        return "{$type->value}:{$authorId}:{$targetId}";
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasOne<Conversation, $this>
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function isPending(): bool
    {
        return $this->status === MatchRequestStatus::Pending;
    }

    public function isAuthor(User $user): bool
    {
        return $this->author_id !== null && $this->author_id === $user->id;
    }

    public function isRecipient(User $user): bool
    {
        return $this->recipient_id !== null && $this->recipient_id === $user->id;
    }

    public function involves(User $user): bool
    {
        return $this->isAuthor($user) || $this->isRecipient($user);
    }

    /**
     * Identifiant de l'autre participant (null si son compte a été supprimé).
     */
    public function otherUserId(User $user): ?int
    {
        return $this->isAuthor($user) ? $this->recipient_id : $this->author_id;
    }

    /**
     * Demandes dont l'utilisateur est l'auteur ou le destinataire.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function involving(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query->where('author_id', $user->id)->orWhere('recipient_id', $user->id));
    }
}
