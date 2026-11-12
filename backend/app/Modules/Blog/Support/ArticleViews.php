<?php

namespace App\Modules\Blog\Support;

use App\Models\Media;
use App\Modules\Blog\Enums\AuthorKind;

/**
 * Petites présentations communes aux Resources du module.
 */
final class ArticleViews
{
    /**
     * Couverture : uuid (pour la garder lors d'une modification), URL et dimensions. Jamais
     * l'id, le disque ni le chemin.
     *
     * @return array{uuid: string, url: string, width: int, height: int}|null
     */
    public static function cover(?Media $media): ?array
    {
        return $media === null ? null : [
            'uuid' => $media->uuid,
            'url' => $media->url(),
            'width' => $media->width,
            'height' => $media->height,
        ];
    }

    /**
     * Corps présenté (liens avec rel, images avec URL), en chargeant les médias des images.
     *
     * @param  array<string, mixed>|null  $document
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    public static function body(?array $document, AuthorKind $kind): array
    {
        return ArticleBodyPresenter::present($document, $kind, ArticleImages::mediaFor([$document]));
    }
}
