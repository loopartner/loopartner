<?php

namespace App\Modules\Accounts\Actions;

use App\Modules\Accounts\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Action publique du module Accounts : limite une requête d'un autre module (offres du module
 * Marketplace) aux lignes dont l'utilisateur a un profil prestataire publié et non masqué par la
 * modération, par une sous-requête. Le modèle ProviderProfile n'est jamais exposé.
 */
class PublishedProviderConstraint
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $userIdColumn  colonne de la requête qui porte l'identifiant de l'utilisateur
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $userIdColumn): Builder
    {
        return $query->whereIn($userIdColumn, ProviderProfile::query()->published()->select('user_id'));
    }
}
