<?php

namespace App\Modules\Blog\Actions;

use App\Models\User;
use App\Modules\Blog\Enums\ArticleNotice;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Notifications\ArticleNotification;
use App\Modules\Blog\Support\ArticlePublisher;
use App\Modules\Blog\Support\BlogModeration;
use App\Support\AdminAudit;
use App\Support\Moderation\VersionGuard;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Validation d'un article en attente par un modérateur : il est publié (slug calculé à la
 * première publication) et l'auteur est prévenu.
 *
 * 403 sur son propre article ; 409 si l'article n'est plus en attente, a changé depuis la
 * lecture du modérateur (version), ou si l'auteur ne peut plus publier.
 */
class ApproveArticle
{
    /**
     * @throws AccessDeniedHttpException
     * @throws ConflictHttpException
     */
    public function handle(User $moderator, Article $article, int $version): Article
    {
        return DB::transaction(function () use ($moderator, $article, $version): Article {
            $article = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            BlogModeration::assertNotOwn($moderator, $article->user_id, $article->uuid);

            if ($article->status !== ArticleStatus::PendingReview) {
                throw new ConflictHttpException('Cet article n\'est plus en attente de validation.');
            }

            VersionGuard::assertSame($version, $article->version);
            $author = BlogModeration::assertAuthorEligible($article);

            ArticlePublisher::publish($article);

            AdminAudit::record($moderator, 'blog.article.approved', 'article', $article->uuid, $article->version);
            SecurityLog::record('blog.article_approved', ['user_id' => $moderator->id, 'article' => $article->uuid, 'version' => $article->version]);

            ArticleNotification::sendTo($author, $article, ArticleNotice::Approved);

            return $article;
        });
    }
}
