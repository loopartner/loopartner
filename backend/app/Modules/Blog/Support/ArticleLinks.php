<?php

namespace App\Modules\Blog\Support;

use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Support\Links\ExternalLink;
use App\Support\Links\LinkInspection;

/**
 * Liens d'un article mis en évidence pour la modération (brique du noyau LinkInspection) :
 * liens du corps et liens écrits dans le titre, le chapô et les champs de référencement, avec
 * les domaines nouveaux pour cet auteur (previously_approved : domaine déjà présent dans un
 * autre de ses articles publiés) et les noms de domaine internationalisés (punycode).
 */
final class ArticleLinks
{
    /** @var list<string> */
    private const TEXT_FIELDS = ['title', 'excerpt', 'seo_title', 'seo_description', 'author_name'];

    private const HISTORY_LIMIT = 50;

    /**
     * @param  array<string, mixed>  $content
     * @return array{links: list<array{field: string, link: string, host: string|null}>, domains: list<array{host: string, fields: list<string>, punycode: bool, previously_approved: bool}>}
     */
    public static function inspect(array $content, ?int $authorId, ?int $articleId): array
    {
        return LinkInspection::inspect(self::links($content), self::approvedHosts($authorId, $articleId));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array{field: string, link: string, host: string|null}>
     */
    private static function links(array $content): array
    {
        $links = [];

        foreach (self::TEXT_FIELDS as $field) {
            $text = $content[$field] ?? null;

            if (is_string($text)) {
                foreach (ExternalLink::extract($text) as $link) {
                    $links[] = ['field' => $field, 'link' => $link, 'host' => ExternalLink::textHost($link)];
                }
            }
        }

        /** @var array<string, mixed>|null $body */
        $body = is_array($content['body'] ?? null) ? $content['body'] : null;

        return [...$links, ...ArticleBody::links($body)];
    }

    /**
     * @return list<string>
     */
    private static function approvedHosts(?int $authorId, ?int $articleId): array
    {
        if ($authorId === null) {
            return [];
        }

        $hosts = [];

        Article::query()
            ->where('user_id', $authorId)
            ->where('status', ArticleStatus::Published->value)
            ->when($articleId !== null, fn ($query) => $query->whereKeyNot($articleId))
            ->latest('published_at')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->each(function (Article $article) use (&$hosts): void {
                foreach (self::links(ArticleContent::of($article)) as $link) {
                    if ($link['host'] !== null) {
                        $hosts[] = $link['host'];
                    }
                }
            });

        return array_values(array_unique($hosts));
    }
}
