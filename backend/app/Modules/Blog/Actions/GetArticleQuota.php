<?php

namespace App\Modules\Blog\Actions;

use App\Models\User;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Support\ArticleQuota;
use App\Modules\Blog\Support\Authors;

/**
 * Action publique du module Blog : état du quota gratuit d'articles d'un utilisateur, en
 * données simples, pour l'afficher partout (GET /me du module Accounts).
 *
 * - prestataire ou entrepreneur : {premium, limit, used, remaining} ; un auteur premium n'est
 *   pas bloqué par le quota, mais ses soumissions restent comptées ;
 * - autres rôles (particulier, équipe) : null.
 */
class GetArticleQuota
{
    /**
     * @return array{premium: bool, limit: int, used: int, remaining: int}|null
     */
    public function handle(User $user): ?array
    {
        $kind = Authors::kindOf($user);

        if ($kind === null || $kind === AuthorKind::Editorial) {
            return null;
        }

        return ArticleQuota::status($user->id);
    }
}
