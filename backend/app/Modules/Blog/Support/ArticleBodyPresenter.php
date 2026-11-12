<?php

namespace App\Modules\Blog\Support;

use App\Models\Media;
use App\Modules\Blog\Enums\AuthorKind;

/**
 * Corps d'un article tel que l'API le renvoie : le document normalisé, avec
 *
 * - pour chaque lien, un objet {"href", "rel"} dont le frontend reprend le rel tel quel ;
 * - pour chaque image, son URL publique et ses dimensions (jamais l'id, le disque ni le chemin).
 *   Une image dont le média n'existe plus (compte de l'auteur supprimé) est omise.
 *
 * Règle des liens (référencement) : seuls les articles de l'équipe (AuthorKind::Editorial) ont
 * des liens suivis. Ceux de TOUS les auteurs extérieurs portent rel="nofollow ugc noopener
 * noreferrer", premium compris : un auteur premium paie pour publier, ses liens sont des liens
 * payants pour les moteurs de recherche, et un lien payant suivi expose le site à une pénalité.
 * Ce n'est donc jamais un avantage premium possible : le rel ne dépend que du type d'auteur.
 */
final class ArticleBodyPresenter
{
    public const REL_EDITORIAL = 'noopener noreferrer';

    public const REL_EXTERNAL = 'nofollow ugc noopener noreferrer';

    public static function rel(AuthorKind $kind): string
    {
        return $kind->isEditorial() ? self::REL_EDITORIAL : self::REL_EXTERNAL;
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @param  array<string, Media>  $media  médias des images, par uuid
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    public static function present(?array $document, AuthorKind $kind, array $media): array
    {
        $rel = self::rel($kind);
        $blocks = [];

        foreach (ArticleBody::blocks($document) as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'image') {
                $image = $media[(string) ($block['media'] ?? '')] ?? null;

                if ($image === null) {
                    continue;
                }

                $blocks[] = [
                    'type' => 'image',
                    'media' => $image->uuid,
                    'url' => $image->url(),
                    'width' => $image->width,
                    'height' => $image->height,
                    'alt' => $block['alt'] ?? null,
                    'caption' => $block['caption'] ?? null,
                ];

                continue;
            }

            if (isset($block['content']) && is_array($block['content'])) {
                $block['content'] = self::segments($block['content'], $rel);
            }

            if ($type === 'list' && isset($block['items']) && is_array($block['items'])) {
                $block['items'] = array_map(fn ($item): array => self::segments(is_array($item) ? $item : [], $rel), array_values($block['items']));
            }

            $blocks[] = $block;
        }

        return ['version' => ArticleBody::VERSION, 'blocks' => $blocks];
    }

    /**
     * @param  array<array-key, mixed>  $segments
     * @return list<array{text: string, marks: list<string>, link: array{href: string, rel: string}|null}>
     */
    private static function segments(array $segments, string $rel): array
    {
        $presented = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $link = $segment['link'] ?? null;

            /** @var list<string> $marks */
            $marks = is_array($segment['marks'] ?? null) ? array_values($segment['marks']) : [];

            $presented[] = [
                'text' => is_string($segment['text'] ?? null) ? $segment['text'] : '',
                'marks' => $marks,
                'link' => is_string($link) ? ['href' => $link, 'rel' => $rel] : null,
            ];
        }

        return $presented;
    }
}
