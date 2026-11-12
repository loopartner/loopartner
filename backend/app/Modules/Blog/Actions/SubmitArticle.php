<?php

namespace App\Modules\Blog\Actions;

use App\Models\User;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Exceptions\PremiumRequired;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Support\ArticleContent;
use App\Modules\Blog\Support\ArticlePublisher;
use App\Modules\Blog\Support\ArticleQuota;
use App\Support\AdminAudit;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Soumission d'un article à la validation de l'équipe, ou publication directe d'un article de
 * l'équipe (rédacteur ou admin, 2FA de session vérifiée par le contrôleur).
 *
 * - Brouillon, refusé ou dépublié uniquement (409 sinon) ; contenu complet (422 sinon).
 * - Auteur extérieur : quota gratuit (403 premium_required), plafond d'articles en attente et
 *   de nouvelles soumissions (409), voir ArticleQuota.
 */
class SubmitArticle
{
    /**
     * @throws ConflictHttpException
     * @throws PremiumRequired
     * @throws ValidationException
     */
    public function handle(User $author, Article $article): Article
    {
        return DB::transaction(function () use ($author, $article): Article {
            $article = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            if (! $article->status->isEditable()) {
                throw new ConflictHttpException($article->isPublished()
                    ? 'Cet article est déjà publié.'
                    : 'Cet article est déjà en attente de validation.');
            }

            ArticleContent::assertComplete(ArticleContent::of($article), $article->author_kind);

            if ($article->author_kind->isEditorial()) {
                ArticlePublisher::publish($article);

                AdminAudit::record($author, 'blog.article.published_directly', 'article', $article->uuid, $article->version);
                SecurityLog::record('blog.published_directly', ['user_id' => $author->id, 'article' => $article->uuid]);

                return $article;
            }

            ArticleQuota::lock($author->id);
            ArticleQuota::consumeForSubmission($author, $article);

            $article->forceFill([
                'status' => ArticleStatus::PendingReview,
                'submitted_at' => now(),
                'first_submitted_at' => $article->first_submitted_at ?? now(),
                'submission_count' => $article->submission_count + 1,
            ])->save();

            SecurityLog::record('blog.article_submitted', [
                'user_id' => $author->id,
                'article' => $article->uuid,
                'submission' => $article->submission_count,
            ]);

            return $article;
        });
    }
}
