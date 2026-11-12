<?php

namespace App\Modules\Blog\Actions;

use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Retire un article de la file de validation : il redevient un brouillon modifiable. Sa
 * place dans le quota reste comptée.
 */
class WithdrawSubmission
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(Article $article): Article
    {
        return DB::transaction(function () use ($article): Article {
            $article = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            if ($article->status !== ArticleStatus::PendingReview) {
                throw new ConflictHttpException('Cet article n\'est pas en attente de validation.');
            }

            $article->forceFill([
                'status' => ArticleStatus::Draft,
                'submitted_at' => null,
            ])->save();

            SecurityLog::record('blog.submission_withdrawn', ['article' => $article->uuid]);

            return $article;
        });
    }
}
