<?php

namespace App\Modules\Directory\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Directory\Models\UserBlock;

/**
 * Ce que l'utilisateur connecté a le droit de voir de ses interlocuteurs, calculé une fois pour
 * toute une page de demandes ou de conversations (pas de requête par ligne).
 *
 * - Un prestataire est présenté par son résumé public (Action GetProviderSummaries du module
 *   Accounts) : jamais son nom d'utilisateur, son email ni son identifiant.
 * - Un porteur de projet est présenté par son type de compte et son pays ; son nom n'est
 *   révélé qu'après l'acceptation d'une demande ($revealName).
 * - Un compte supprimé est présenté comme tel ({"deleted": true}).
 *
 * Les rôles des interlocuteurs (relation roles) doivent être chargés.
 *
 * @phpstan-import-type ProviderSummary from GetProviderSummaries
 *
 * @phpstan-type Counterpart array{deleted: bool, account_type: string|null, country: string|null, name: string|null, provider: ProviderSummary|null}
 */
final class ViewerContext
{
    private const PUBLIC_ACCOUNT_TYPES = [
        AccountType::Particulier->value,
        AccountType::Prestataire->value,
        AccountType::Entrepreneur->value,
    ];

    /**
     * @param  array<int, ProviderSummary>  $providers  résumés indexés par identifiant d'utilisateur
     * @param  array<int, true>  $blockedByViewer  utilisateurs bloqués par le lecteur
     * @param  array<int, true>  $blockingViewer  utilisateurs qui ont bloqué le lecteur
     */
    private function __construct(
        public readonly User $viewer,
        private readonly array $providers,
        private readonly array $blockedByViewer,
        private readonly array $blockingViewer,
    ) {}

    /**
     * @param  iterable<User|null>  $others  interlocuteurs du lecteur (null : compte supprimé)
     */
    public static function build(User $viewer, iterable $others): self
    {
        $providerIds = [];
        $otherIds = [];

        foreach ($others as $other) {
            if ($other === null) {
                continue;
            }

            $otherIds[] = $other->id;

            if (self::accountType($other) === AccountType::Prestataire->value) {
                $providerIds[] = $other->id;
            }
        }

        $blocks = $otherIds === [] ? collect() : UserBlock::query()
            ->where(fn ($query) => $query->where('blocker_id', $viewer->id)->whereIn('blocked_id', $otherIds))
            ->orWhere(fn ($query) => $query->where('blocked_id', $viewer->id)->whereIn('blocker_id', $otherIds))
            ->get(['blocker_id', 'blocked_id']);

        return new self(
            $viewer,
            app(GetProviderSummaries::class)->handle($providerIds),
            array_fill_keys($blocks->where('blocker_id', $viewer->id)->pluck('blocked_id')->all(), true),
            array_fill_keys($blocks->where('blocked_id', $viewer->id)->pluck('blocker_id')->all(), true),
        );
    }

    /**
     * Type de compte public de l'utilisateur (particulier, prestataire ou entrepreneur).
     */
    public static function accountType(User $user): ?string
    {
        return $user->getRoleNames()->first(fn (string $role): bool => in_array($role, self::PUBLIC_ACCOUNT_TYPES, true));
    }

    /**
     * @return Counterpart
     */
    public function counterpart(?User $other, bool $revealName): array
    {
        if ($other === null) {
            return ['deleted' => true, 'account_type' => null, 'country' => null, 'name' => null, 'provider' => null];
        }

        $type = self::accountType($other);

        if ($type === AccountType::Prestataire->value) {
            return ['deleted' => false, 'account_type' => $type, 'country' => null, 'name' => null, 'provider' => $this->providers[$other->id] ?? null];
        }

        return [
            'deleted' => false,
            'account_type' => $type,
            'country' => $other->country,
            'name' => $revealName ? $other->name : null,
            'provider' => null,
        ];
    }

    public function hasBlocked(?int $userId): bool
    {
        return $userId !== null && isset($this->blockedByViewer[$userId]);
    }

    public function isBlockedBy(?int $userId): bool
    {
        return $userId !== null && isset($this->blockingViewer[$userId]);
    }
}
