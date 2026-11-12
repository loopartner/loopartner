<?php

namespace App\Modules\Blog\Policies;

use App\Models\User;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Support\Authors;
use Illuminate\Auth\Access\Response;

/**
 * Articles gérés par leur auteur (/me/articles).
 *
 * - Peuvent écrire : rédacteurs et admins (l'équipe), prestataires avec un profil publié et au
 *   moins une catégorie, entrepreneurs (email vérifié : middleware verified).
 * - Seul l'auteur voit, modifie, soumet et supprime son article : tout autre utilisateur reçoit
 *   404 (et non 403), modérateurs compris. La modération passe par ses propres endpoints
 *   (/admin/…, middleware EnsureModerator).
 */
class ArticlePolicy
{
    private const NOT_FOUND = 'Article introuvable.';

    public function viewAnyOwn(User $user): bool
    {
        return Authors::hasAuthorRole($user);
    }

    public function create(User $user): Response
    {
        $kind = Authors::kindOf($user);

        if ($kind === null) {
            return Response::deny();
        }

        if ($kind === AuthorKind::Provider && ! Authors::canWriteAs($user, $kind)) {
            return Response::deny('Publiez votre profil prestataire, avec au moins une catégorie, pour écrire un article.');
        }

        return Response::allow();
    }

    public function view(User $user, Article $article): Response
    {
        return Authors::hasAuthorRole($user) && $article->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound(self::NOT_FOUND);
    }

    public function update(User $user, Article $article): Response
    {
        return $this->view($user, $article);
    }

    public function delete(User $user, Article $article): Response
    {
        return $this->view($user, $article);
    }

    /**
     * Soumettre (ou publier directement, pour l'équipe) : il faut en plus pouvoir toujours
     * écrire des articles de ce type (rôle, profil prestataire encore publié…).
     */
    public function submit(User $user, Article $article): Response
    {
        $ownership = $this->view($user, $article);

        if ($ownership->denied()) {
            return $ownership;
        }

        if (! Authors::canWriteAs($user, $article->author_kind)) {
            return Response::deny($article->author_kind === AuthorKind::Provider
                ? 'Publiez votre profil prestataire, avec au moins une catégorie, pour soumettre un article.'
                : 'Votre type de compte ne permet plus de soumettre cet article.');
        }

        return Response::allow();
    }
}
