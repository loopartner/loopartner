<?php

namespace App\Modules\Accounts\Actions;

use App\Modules\Accounts\Models\ProviderProfile;

/**
 * Action publique du module Accounts : prestataire dont le profil publié a le slug donné.
 *
 * Utilisée par Directory (contact d'un prestataire depuis son profil public) sans exposer le
 * modèle ProviderProfile. Renvoie null si aucun profil publié ne porte ce slug.
 *
 * @phpstan-import-type ProviderSummary from GetProviderSummaries
 */
class FindPublishedProvider
{
    /**
     * @return array{user_id: int, summary: ProviderSummary}|null
     */
    public function handle(string $slug): ?array
    {
        $profile = ProviderProfile::query()->published()->with('logo')->where('slug', $slug)->first();

        return $profile === null ? null : [
            'user_id' => $profile->user_id,
            'summary' => GetProviderSummaries::summary($profile),
        ];
    }
}
