<?php

namespace App\Modules\Blog\Support;

use App\Models\Media;
use App\Models\User;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Images du corps d'un article (usage article_image), suivies dans la table article_media.
 *
 * - Modification directe : les images du nouveau corps sont rattachées à l'article, celles
 *   qui n'y figurent plus sont supprimées (ligne et fichier).
 * - Révision : les nouvelles images sont rattachées à la révision ; l'article garde les
 *   siennes jusqu'à la validation (applyRevision), où celles qui ne figurent plus dans la
 *   version validée sont supprimées. Une révision refusée, retirée ou remplacée perd ses
 *   images (discardRevision).
 *
 * Les fichiers sont toujours supprimés par MediaLibrary, après la validation de la transaction.
 */
final class ArticleImages
{
    private const TABLE = 'article_media';

    /**
     * @return list<int>
     */
    public static function articleMediaIds(int $articleId): array
    {
        /** @var list<int> */
        return DB::table(self::TABLE)->where('article_id', $articleId)->pluck('media_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    public static function revisionMediaIds(int $revisionId): array
    {
        /** @var list<int> */
        return DB::table(self::TABLE)->where('article_revision_id', $revisionId)->pluck('media_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Modification directe du corps d'un article (brouillon, refusé, dépublié, ou article de
     * l'équipe).
     *
     * @param  array<string, mixed>  $document
     */
    public static function syncArticle(User $author, Article $article, array $document): void
    {
        $current = self::articleMediaIds($article->id);
        $ids = app(MediaLibrary::class)->attachMany($author, MediaPurpose::ArticleImage, ArticleBody::imageUuids($document), $current, 'body');

        self::insert(array_diff(array_values($ids), $current), ['article_id' => $article->id]);
        self::deleteMedia(array_diff($current, array_values($ids)));
    }

    /**
     * Révision proposée : les images déjà sur l'article restent sur l'article, les nouvelles
     * sont rattachées à la révision, et les images de la révision précédente qui ne figurent
     * plus dans la version proposée sont supprimées.
     *
     * @param  array<string, mixed>  $document
     */
    public static function syncRevision(User $author, ArticleRevision $revision, Article $article, array $document): void
    {
        $onArticle = self::articleMediaIds($article->id);
        $onRevision = self::revisionMediaIds($revision->id);
        $ids = array_values(app(MediaLibrary::class)->attachMany(
            $author,
            MediaPurpose::ArticleImage,
            ArticleBody::imageUuids($document),
            [...$onArticle, ...$onRevision],
            'body',
        ));

        self::insert(array_diff($ids, $onArticle, $onRevision), ['article_revision_id' => $revision->id]);
        self::deleteMedia(array_diff($onRevision, $ids));
    }

    /**
     * Validation d'une révision : ses images passent sur l'article, et les images de l'article
     * absentes de la version validée sont supprimées.
     *
     * @param  array<string, mixed>  $document
     */
    public static function applyRevision(ArticleRevision $revision, Article $article, array $document): void
    {
        DB::table(self::TABLE)
            ->where('article_revision_id', $revision->id)
            ->update(['article_id' => $article->id, 'article_revision_id' => null]);

        $kept = Media::query()->whereIn('uuid', ArticleBody::imageUuids($document))->pluck('id')->all();

        self::deleteMedia(array_diff(self::articleMediaIds($article->id), $kept));
    }

    /**
     * Révision refusée, retirée ou remplacée : ses images sont supprimées.
     */
    public static function discardRevision(ArticleRevision $revision): void
    {
        self::deleteMedia(self::revisionMediaIds($revision->id));
    }

    /**
     * Suppression d'un article : toutes ses images et celles de ses révisions.
     */
    public static function deleteForArticle(Article $article): void
    {
        $revisionIds = ArticleRevision::query()->where('article_id', $article->id)->pluck('id')->all();

        $mediaIds = DB::table(self::TABLE)
            ->where('article_id', $article->id)
            ->orWhereIn('article_revision_id', $revisionIds)
            ->pluck('media_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        self::deleteMedia($mediaIds);
    }

    /**
     * Médias des images de plusieurs documents, par uuid (une requête pour une page).
     *
     * @param  iterable<array<string, mixed>|null>  $documents
     * @return array<string, Media>
     */
    public static function mediaFor(iterable $documents): array
    {
        $uuids = [];

        foreach ($documents as $document) {
            array_push($uuids, ...ArticleBody::imageUuids($document));
        }

        if ($uuids === []) {
            return [];
        }

        /** @var Collection<string, Media> $media */
        $media = Media::query()->whereIn('uuid', array_unique($uuids))->get()->keyBy('uuid');

        return $media->all();
    }

    /**
     * @param  array<int>  $mediaIds
     * @param  array<string, int>  $owner
     */
    private static function insert(array $mediaIds, array $owner): void
    {
        foreach ($mediaIds as $mediaId) {
            DB::table(self::TABLE)->insert([...$owner, 'media_id' => $mediaId, 'created_at' => now()]);
        }
    }

    /**
     * @param  array<int>  $mediaIds
     */
    private static function deleteMedia(array $mediaIds): void
    {
        if ($mediaIds === []) {
            return;
        }

        $library = app(MediaLibrary::class);

        // La ligne article_media disparaît avec le média (clé étrangère en cascade).
        Media::query()->whereKey(array_values($mediaIds))->get()->each(fn (Media $media) => $library->delete($media));
    }
}
