<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use App\Modules\Marketplace\Enums\ClientKind;
use App\Modules\Marketplace\Enums\DeclineReason;
use App\Modules\Marketplace\Enums\DepositKind;
use App\Modules\Marketplace\Enums\QuoteStatus;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Marketplace\QuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devis structuré d'un prestataire à un porteur de projet, dans une demande de mise en
 * relation acceptée (décision 36).
 *
 * - Visible de ses deux parties seulement : le prestataire (à tout statut) et le client (une
 *   fois envoyé). Tout autre utilisateur reçoit 404, admins compris.
 * - Montants en centimes, quantités en millièmes, taux en points de base (entiers).
 * - Contenu figé à l'envoi (snapshots, taux copiés, content_hash) : un devis envoyé ne se
 *   modifie plus ; une nouvelle version le remplace.
 * - Seuls les champs de contenu sont assignables ; numéro, parties, statut, totaux, snapshots,
 *   empreinte et dates sont fixés par les Actions.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $number
 * @property int $version
 * @property int $match_request_id
 * @property int|null $project_id
 * @property int|null $provider_id
 * @property int|null $client_id
 * @property int|null $revision_of_id
 * @property ClientKind $client_kind
 * @property QuoteStatus $status
 * @property string $title
 * @property string $scope
 * @property string|null $offer_uuid
 * @property string|null $offer_title
 * @property \ArrayObject<string, mixed> $client_snapshot
 * @property \ArrayObject<string, mixed>|null $issuer_snapshot
 * @property string|null $issuer_logo copie figée du logo Premium (base64 d'un PNG), jamais exposée
 * @property string $currency
 * @property int $total_ht_cents
 * @property int $total_vat_cents
 * @property int $total_ttc_cents
 * @property list<array<string, mixed>>|null $vat_breakdown
 * @property int $validity_days
 * @property CarbonInterface|null $valid_until
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $start_date
 * @property string|null $duration
 * @property string|null $payment_terms
 * @property int|null $deposit_cents
 * @property DepositKind|null $deposit_kind
 * @property bool $quote_is_free
 * @property int|null $quote_fee_cents
 * @property bool|null $keep_replaced_parts
 * @property string|null $notes
 * @property string|null $content_hash
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $accepted_at
 * @property string|null $acceptance_ip
 * @property array<string, mixed>|null $acceptance_data
 * @property CarbonInterface|null $declined_at
 * @property DeclineReason|null $decline_reason
 * @property string|null $decline_comment
 * @property CarbonInterface|null $withdrawn_at
 * @property CarbonInterface|null $expired_at
 * @property CarbonInterface|null $superseded_at
 * @property CarbonInterface|null $withdrawal_exercised_at
 * @property CarbonInterface|null $moderation_restricted_at
 * @property string|null $moderation_decision_uuid
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, QuoteLine> $lines
 */
#[UseFactory(QuoteFactory::class)]
#[Fillable(['title', 'scope', 'validity_days', 'start_date', 'duration', 'payment_terms', 'deposit_cents', 'deposit_kind', 'quote_is_free', 'quote_fee_cents', 'keep_replaced_parts', 'notes'])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    protected $table = 'marketplace_quotes';

    /**
     * La copie figée du logo n'est jamais sérialisée (réponses, journal) : elle n'est lue que
     * par le PDF.
     *
     * @var list<string>
     */
    protected $hidden = ['issuer_logo'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'version' => 1,
        'quote_is_free' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'client_kind' => ClientKind::class,
            'status' => QuoteStatus::class,
            'client_snapshot' => AsEncryptedArrayObject::class,
            'issuer_snapshot' => AsEncryptedArrayObject::class,
            'total_ht_cents' => 'integer',
            'total_vat_cents' => 'integer',
            'total_ttc_cents' => 'integer',
            'vat_breakdown' => 'array',
            'validity_days' => 'integer',
            'valid_until' => 'date',
            'expires_at' => 'datetime',
            'start_date' => 'date',
            'deposit_cents' => 'integer',
            'deposit_kind' => DepositKind::class,
            'quote_is_free' => 'boolean',
            'quote_fee_cents' => 'integer',
            'keep_replaced_parts' => 'boolean',
            'acceptance_data' => 'array',
            'decline_reason' => DeclineReason::class,
            'decline_comment' => 'encrypted',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'expired_at' => 'datetime',
            'superseded_at' => 'datetime',
            'withdrawal_exercised_at' => 'datetime',
            'moderation_restricted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<QuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position')->orderBy('id');
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

    public function isProvider(User $user): bool
    {
        return $this->provider_id !== null && $this->provider_id === $user->id;
    }

    /**
     * Le client ne voit un devis qu'une fois envoyé (jamais un brouillon).
     */
    public function isClient(User $user): bool
    {
        return $this->client_id !== null && $this->client_id === $user->id && $this->status !== QuoteStatus::Draft;
    }

    /**
     * Envoyé et dans sa durée de validité : il peut être accepté.
     */
    public function isOpen(): bool
    {
        return $this->status === QuoteStatus::Sent && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * Fin du délai de rétractation d'un consommateur (null sans acceptation ou pour un
     * professionnel).
     */
    public function withdrawalEndsAt(): ?CarbonInterface
    {
        if ($this->accepted_at === null || $this->client_kind !== ClientKind::Consumer) {
            return null;
        }

        return $this->accepted_at->copy()->addDays((int) config('marketplace.withdrawal_days'));
    }
}
