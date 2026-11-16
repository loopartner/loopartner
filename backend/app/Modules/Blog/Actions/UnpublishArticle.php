<?php

namespace App\Modules\Blog\Actions;

use App\Models\User;
use App\Modules\Blog\Enums\ArticleNotice;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Notifications\ArticleNotification;
use App\Modules\Blog\Support\BlogModeration;
use App\Modules\Blog\Support\PendingRevisions;
use App\Support\AdminAudit;
use App\Support\Moderation\ContentModerated;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Dépublication d'un article publié par un modérateur, avec un motif communiqué à l'auteur.
 * L'article quitte le blog tout de suite (404), sa révision en attente devient sans objet, et
 * il garde son slug : corrigé et validé de nouveau, il retrouve la même adresse.
 *
 * 403 sur son propre article, et pour un rédacteur sur un article de la rédaction ; 409 si
 * l'article n'est pas publié.
 */
class UnpublishArticle
{
    /**
     * @throws AccessDeniedHttpException
     * @throws ConflictHttpException
     */
    public function handle(User $moderator, Article $article, string $reason): Article
    {
        return DB::transaction(function () use ($moderator, $article, $reason): Article {
            $article = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            BlogModeration::assertCanUnpublish($moderator, $article);

            if (! $article->isPublished()) {
                throw new ConflictHttpException('Cet article n\'est pas publié.');
            }

            PendingRevisions::closeFor($article);

            $article->forceFill([
                'status' => ArticleStatus::Unpublished,
                'unpublished_at' => now(),
                'unpublication_reason' => $reason,
            ])->save();

            AdminAudit::record($moderator, 'blog.article.unpublished', 'article', $article->uuid, $article->version, $reason);
            // Exposé des motifs consultable et contestable (module Moderation).
            ContentModerated::dispatch('article', $article->slug ?? $article->uuid, $article->user_id, $article->title, $reason, $moderator->id, ContentModerated::WITHDRAWN);
            SecurityLog::record('blog.article_unpublished', ['user_id' => $moderator->id, 'article' => $article->uuid]);

            ArticleNotification::sendTo($article->user, $article, ArticleNotice::Unpublished, $reason);

            return $article;
        });
    }
}
