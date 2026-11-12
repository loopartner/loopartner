<?php

namespace App\Support\Media;

/**
 * Usage d'un média. Chaque usage définit ses limites d'upload (poids, dimensions, rôles).
 */
enum MediaPurpose: string
{
    case Avatar = 'avatar';
    case ProviderLogo = 'provider_logo';
    case EventCover = 'event_cover';
    case ArticleCover = 'article_cover';
    case ArticleImage = 'article_image';

    /**
     * Rôles qui peuvent écrire un article (module Blog) : l'équipe et les auteurs extérieurs.
     *
     * @var list<string>
     */
    private const ARTICLE_AUTHOR_ROLES = ['prestataire', 'entrepreneur', 'redacteur', 'admin'];

    public function limits(): PurposeLimits
    {
        return match ($this) {
            self::Avatar => new PurposeLimits(
                maxKilobytes: 5 * 1024,
                minWidth: 128,
                minHeight: 128,
                maxWidth: 512,
                maxHeight: 512,
            ),
            self::ProviderLogo => new PurposeLimits(
                maxKilobytes: 5 * 1024,
                minWidth: 128,
                minHeight: 128,
                maxWidth: 1024,
                maxHeight: 1024,
                allowedRoles: ['prestataire'],
            ),
            // Couverture d'un événement (module Events), au format 16:9 conseillé : réservée aux
            // rôles qui peuvent organiser un événement.
            self::EventCover => new PurposeLimits(
                maxKilobytes: 5 * 1024,
                minWidth: 960,
                minHeight: 540,
                maxWidth: 1920,
                maxHeight: 1080,
                allowedRoles: ['prestataire', 'entrepreneur', 'redacteur', 'admin'],
            ),
            // Couverture d'un article (module Blog), aussi utilisée comme image de partage
            // (1200 × 630 au minimum) : réservée aux rôles qui peuvent écrire un article.
            self::ArticleCover => new PurposeLimits(
                maxKilobytes: 5 * 1024,
                minWidth: 1200,
                minHeight: 630,
                maxWidth: 2400,
                maxHeight: 1260,
                allowedRoles: self::ARTICLE_AUTHOR_ROLES,
            ),
            // Image dans le corps d'un article (module Blog).
            self::ArticleImage => new PurposeLimits(
                maxKilobytes: 5 * 1024,
                minWidth: 320,
                minHeight: 200,
                maxWidth: 1600,
                maxHeight: 1600,
                allowedRoles: self::ARTICLE_AUTHOR_ROLES,
            ),
        };
    }
}
