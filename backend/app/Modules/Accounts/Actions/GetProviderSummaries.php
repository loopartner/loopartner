<?php

namespace App\Modules\Accounts\Actions;

use App\Modules\Accounts\Models\ProviderProfile;

/**
 * Action publique du module Accounts : résumé public des prestataires donnés, pour d'autres
 * modules (Directory : mise en relation et messagerie), sans exposer le modèle ProviderProfile.
 *
 * Le résumé ne contient que ce que montre déjà la page publique d'un prestataire : ni email, ni
 * nom de l'utilisateur, ni identifiant. "slug" vaut null si le profil n'est plus publié (sa
 * page publique n'existe plus). Un utilisateur sans profil est absent du résultat.
 *
 * @phpstan-type ProviderSummary array{display_name: string, slug: string|null, headline: string|null, city: string|null, country: string|null, logo_url: string|null}
 */
class GetProviderSummaries
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, ProviderSummary> résumés indexés par identifiant d'utilisateur
     */
    public function handle(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return ProviderProfile::query()
            ->with('logo')
            ->whereIn('user_id', array_values(array_unique($userIds)))
            ->get()
            ->mapWithKeys(fn (ProviderProfile $profile): array => [$profile->user_id => self::summary($profile)])
            ->all();
    }

    /**
     * @return ProviderSummary
     */
    public static function summary(ProviderProfile $profile): array
    {
        return [
            'display_name' => $profile->display_name,
            'slug' => $profile->isPublished() ? $profile->slug : null,
            'headline' => $profile->headline,
            'city' => $profile->city,
            'country' => $profile->country,
            'logo_url' => $profile->logo?->url(),
        ];
    }
}
