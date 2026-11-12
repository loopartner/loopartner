<?php

namespace App\Modules\Blog\Actions;

use App\Models\Media;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRetiredSlug;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Support\ArticleImages;
use App\Support\Media\MediaLibrary;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique pour supprimer un article (auteur, suppression de compte, purge) :
 * images du corps, couvertures (article et révisions) supprimées avec leurs fichiers, slug mis
 * en retraite s'il a été publié (GET /articles/{slug} répond 410, et le slug n'est pas repris).
 *
 * La place de l'article dans le quota gratuit reste comptée (article_quota_entries).
 */
class DeleteArticle
{
    public function __construct(private MediaLibrary $library) {}

    public function handle(Article $article): void
    {
        DB::transaction(function () use ($article): void {
            ArticleImages::deleteForArticle($article);

            $coverIds = ArticleRevision::query()
                ->where('article_id', $article->id)
                ->whereNotNull('cover_media_id')
                ->pluck('cover_media_id')
                ->push($article->cover_media_id)
                ->filter()
                ->all();

            Media::query()->whereKey($coverIds)->get()->each(fn (Media $media) => $this->library->delete($media));

            if ($article->slug !== null && ! ArticleRetiredSlug::query()->where('slug', $article->slug)->exists()) {
                (new ArticleRetiredSlug)->forceFill(['slug' => $article->slug, 'retired_at' => now()])->save();
            }

            // Révisions et catégories : clés étrangères en cascade.
            $article->delete();
        });
    }
}
