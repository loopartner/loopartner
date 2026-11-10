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
            // Pas encore disponibles : définis avec les modules Events et Blog.
            self::EventCover, self::ArticleCover => null,
        };
    }

    public function isUploadable(): bool
    {
        return $this->limits() !== null;
    }
}
