<?php

namespace App\Modules\Accounts\Models;

use App\Models\User;
use App\Modules\Accounts\Enums\PremiumSource;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Accounts\PremiumGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Période de statut premium d'un utilisateur. Privé au module Accounts : les autres modules
 * passent par les Actions publiques IsPremium et GetPremiumStatus.
 *
 * Aucun attribut n'est assignable en masse : les périodes sont créées et retirées par les
 * Actions GrantPremium et RevokePremium (plus tard, par le paiement).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property PremiumSource $source
 * @property Carbon|CarbonImmutable $starts_at
 * @property Carbon|CarbonImmutable|null $ends_at
 * @property string|null $external_reference
 * @property int|null $granted_by
 * @property Carbon|CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
#[UseFactory(PremiumGrantFactory::class)]
class PremiumGrant extends Model
{
    /** @use HasFactory<PremiumGrantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => PremiumSource::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * Période en cours : commencée, pas terminée, pas retirée.
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ! $this->starts_at->isFuture()
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * Période en cours ou à venir (ni terminée, ni retirée).
     */
    public function isCurrentOrUpcoming(): bool
    {
        return $this->revoked_at === null && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * Périodes en cours.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * Périodes en cours ou à venir.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function currentOrUpcoming(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
