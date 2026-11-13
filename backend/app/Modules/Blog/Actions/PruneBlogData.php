<?php

namespace App\Modules\Blog\Actions;

use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRetiredSlug;
use App\Modules\Blog\Models\ArticleRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Minimisation des données du Blog (tâche blog:prune, chaque jour), durées en mois dans
 * config/security.php (retention) :
 *
 * - révisions décidées (validées, refusées, retirées, remplacées) depuis article_revisions_months :
 *   supprimées (la version publiée et le journal d'audit restent) ;
 * - brouillons jamais soumis et non modifiés depuis abandoned_article_drafts_months : supprimés
 *   avec leurs images ;
 * - articles refusés ou dépubliés non modifiés depuis closed_articles_months : supprimés avec
 *   leurs images (le décompte du quota gratuit reste) ;
 * - slugs retirés depuis retired_article_slugs_months : supprimés.
 *
 * Les articles passent par DeleteArticle (fichiers supprimés, slug mis en retraite).
 */
class PruneBlogData
{
    public function __construct(private DeleteArticle $deleteArticle) {}

    /**
     * @return array{revisions: int, drafts: int, closed: int, slugs: int}
     */
    public function handle(): array
    {
        $months = fn (string $key): int => (int) config("security.retention.{$key}");

        $revisions = ArticleRevision::query()
            ->where('status', '!=', RevisionStatus::Pending->value)
            ->where('decided_at', '<=', now()->subMonths($months('article_revisions_months')))
            ->whereNull('cover_media_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('article_media')->whereColumn('article_media.article_revision_id', 'article_revisions.id'))
            ->delete();

        $drafts = $this->deleteArticles(Article::query()
            ->where('status', ArticleStatus::Draft->value)
            ->whereNull('first_submitted_at')
            ->where('updated_at', '<=', now()->subMonths($months('abandoned_article_drafts_months'))));

        $closed = $this->deleteArticles(Article::query()
            ->whereIn('status', [ArticleStatus::Rejected->value, ArticleStatus::Unpublished->value])
            ->where('updated_at', '<=', now()->subMonths($months('closed_articles_months'))));

        $slugs = ArticleRetiredSlug::query()
            ->where('retired_at', '<=', now()->subMonths($months('retired_article_slugs_months')))
            ->delete();

        return ['revisions' => $revisions, 'drafts' => $drafts, 'closed' => $closed, 'slugs' => $slugs];
    }

    /**
     * @param  Builder<Article>  $query
     */
    private function deleteArticles(Builder $query): int
    {
        $deleted = 0;

        $query->chunkById(100, function (Collection $articles) use (&$deleted): void {
            foreach ($articles as $article) {
                /** @var Article $article */
                $this->deleteArticle->handle($article);
                $deleted++;
            }
        });

        return $deleted;
    }
}
