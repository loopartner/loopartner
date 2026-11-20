<?php

namespace App\Modules\Accounts\Actions;

use App\Modules\Accounts\Models\PremiumGrant;

/**
 * Action publique du module Accounts : statut premium d'un utilisateur, en données simples.
 *
 * - active : une période est en cours ;
 * - source, starts_at, ends_at : la période en cours (celle qui finit le plus tard si plusieurs
 *   se recouvrent), ou null. Les dates sont en UTC (ISO 8601).
 *
 * Ni motif, ni admin, ni référence de paiement.
 *
 * @phpstan-type PremiumStatus array{active: bool, source: string|null, starts_at: string|null, ends_at: string|null}
 */
class GetPremiumStatus
{
    /**
     * @return PremiumStatus
     */
    public function handle(int $userId): array
    {
        /** @var PremiumGrant|null $grant */
        $grant = PremiumGrant::query()
            ->active()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->sortByDesc(fn (PremiumGrant $grant): int => $grant->ends_at?->getTimestamp() ?? PHP_INT_MAX)
            ->first();

        return [
            'active' => $grant !== null,
            'source' => $grant?->source->value,
            'starts_at' => $grant?->starts_at->toIso8601ZuluString(),
            'ends_at' => $grant?->ends_at?->toIso8601ZuluString(),
        ];
    }
}
