<?php

namespace App\Support\Media;

/**
 * Usage d'un média. Chaque usage définit ses limites d'upload.
 *
 * Un usage sans limites (limits() renvoie null) existe déjà dans le code mais n'est pas
 * encore ouvert à l'upload : ses limites et ses rôles seront définis par le module concerné.
 */
enum MediaPurpose: string
{
    case Avatar = 'avatar';
    case ProviderLogo = 'provider_logo';
    case EventCover = 'event_cover';
    case ArticleCover = 'article_cover';

    public function limits(): ?PurposeLimits
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
            // Pas encore disponible : défini avec le module Blog.
            self::ArticleCover => null,
        };
    }

    public function isUploadable(): bool
    {
        return $this->limits() !== null;
    }
}
