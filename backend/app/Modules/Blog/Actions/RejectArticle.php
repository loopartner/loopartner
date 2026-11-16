<?php

namespace App\Modules\Blog\Actions;

use App\Models\User;
use App\Modules\Blog\Enums\ArticleNotice;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Notifications\ArticleNotification;
use App\Modules\Blog\Support\BlogModeration;
use App\Support\AdminAudit;
use App\Support\Moderation\ContentModerated;
use App\Support\Moderation\VersionGuard;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Refus d'un article en attente, avec un motif communiqué à l'auteur (email et espace auteur).
 * L'article redevient modifiable et peut être soumis de nouveau (ArticleQuota plafonne les
 * nouvelles soumissions). Le motif est écrit dans le journal d'audit, jamais dans le canal
 * security.
 */
class RejectArticle
{
    /**
     * @throws AccessDeniedHttpException
     * @throws ConflictHttpException
     */
    public function handle(User $moderator, Article $article, int $version, string $reason): Article
    {
        return DB::transaction(function () use ($moderator, $article, $version, $reason): Article {
            $article = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            BlogModeration::assertNotOwn($moderator, $article->user_id, $article->uuid);

            if ($article->status !== ArticleStatus::PendingReview) {
                throw new ConflictHttpException('Cet article n\'est plus en attente de validation.');
            }

            VersionGuard::assertSame($version, $article->version);

            $article->forceFill([
                'status' => ArticleStatus::Rejected,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
                'submitted_at' => null,
            ])->save();

            AdminAudit::record($moderator, 'blog.article.rejected', 'article', $article->uuid, $article->version, $reason);
            // Exposé des motifs consultable et contestable (module Moderation).
            ContentModerated::dispatch('article', $article->slug ?? $article->uuid, $article->user_id, $article->title, $reason, $moderator->id, ContentModerated::REFUSED);
            SecurityLog::record('blog.article_rejected', ['user_id' => $moderator->id, 'article' => $article->uuid, 'version' => $article->version]);

            ArticleNotification::sendTo($article->user, $article, ArticleNotice::Rejected, $reason);

            return $article;
        });
    }
}
