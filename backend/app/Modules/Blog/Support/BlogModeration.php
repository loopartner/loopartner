<?php

namespace App\Modules\Blog\Support;

use App\Models\User;
use App\Modules\Blog\Models\Article;
use App\Support\SecurityLog;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Contrôles communs aux décisions de modération du Blog (rédacteurs et admins, avec la 2FA de
 * session vérifiée par EnsureModerator).
 *
 * - Personne ne modère son propre article ni sa propre révision (403, journalisé) : la
 *   validation passe toujours par un second regard.
 * - La dépublication d'un article de la rédaction est réservée aux admins (403 pour un
 *   rédacteur) : un rédacteur ne retire pas le travail d'un collègue sans contrôle.
 */
final class BlogModeration
{
    public const ROLES = [Authors::REDACTEUR, Authors::ADMIN];

    /**
     * @throws AccessDeniedHttpException
     */
    public static function assertNotOwn(User $moderator, ?int $authorId, string $articleUuid): void
    {
        if ($authorId !== null && $authorId === $moderator->id) {
            SecurityLog::record('blog.self_moderation_denied', ['user_id' => $moderator->id, 'article' => $articleUuid], 'warning');

            throw new AccessDeniedHttpException('Vous ne pouvez pas modérer votre propre article : un autre membre de l\'équipe doit s\'en charger.');
        }
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public static function assertCanUnpublish(User $moderator, Article $article): void
    {
        self::assertNotOwn($moderator, $article->user_id, $article->uuid);

        if ($article->author_kind->isEditorial() && ! Authors::isAdmin($moderator)) {
            throw new AccessDeniedHttpException('Seul un admin peut dépublier un article de la rédaction.');
        }
    }

    /**
     * L'auteur peut-il toujours publier (compte existant, rôle, profil prestataire publié) ?
     *
     * @throws ConflictHttpException
     */
    public static function assertAuthorEligible(Article $article): User
    {
        $author = $article->user;

        if (! Authors::canWriteAs($author, $article->author_kind)) {
            throw new ConflictHttpException('L\'auteur ne peut plus publier cet article (compte, rôle ou profil prestataire).');
        }

        /** @var User $author */
        return $author;
    }
}
