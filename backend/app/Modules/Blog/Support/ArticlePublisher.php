<?php

namespace App\Modules\Blog\Support;

use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRetiredSlug;
use Illuminate\Support\Str;

/**
 * Publication d'un article (validation par un modérateur, ou publication directe de l'équipe).
 *
 * Le slug est calculé à la PREMIÈRE publication, à partir du titre validé, puis ne change plus
 * (même si une révision modifie le titre, ou après une dépublication et une nouvelle
 * validation) : les URL publiques restent stables. Un slug déjà pris, ou retiré (article
 * supprimé), reçoit un suffixe numérique.
 */
final class ArticlePublisher
{
    private const SLUG_BASE_LENGTH = 100;

    /** Slugs réservés par les routes publiques (GET /articles/sitemap). */
    private const RESERVED = ['sitemap'];

    public static function publish(Article $article): void
    {
        $article->forceFill([
            'status' => ArticleStatus::Published,
            'slug' => $article->slug ?? self::uniqueSlug($article->title),
            'published_at' => $article->published_at ?? now(),
            'content_updated_at' => now(),
            'rejected_at' => null,
            'rejection_reason' => null,
            'unpublished_at' => null,
            'unpublication_reason' => null,
        ])->save();
    }

    public static function uniqueSlug(string $title): string
    {
        $base = trim(Str::substr(Str::slug($title), 0, self::SLUG_BASE_LENGTH), '-');
        $base = $base === '' || in_array($base, self::RESERVED, true) ? trim('article-'.$base, '-') : $base;

        $slug = $base;
        $suffix = 1;

        while (self::taken($slug)) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private static function taken(string $slug): bool
    {
        return Article::query()->where('slug', $slug)->exists()
            || ArticleRetiredSlug::query()->where('slug', $slug)->exists();
    }
}
