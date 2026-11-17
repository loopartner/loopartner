<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Models\CalendarFeed;
use App\Support\Calendar\FeedToken;

/**
 * Jeton de l'URL privée d'abonnement au calendrier des échéances. Le mécanisme (sélecteur
 * public de 16 caractères, secret de 40 caractères dont seule l'empreinte HMAC-SHA256 est
 * stockée, comparaison en temps constant, même calcul pour un sélecteur inconnu) est celui de
 * la brique du noyau App\Support\Calendar\FeedToken. Le jeton n'est jamais journalisé.
 */
final class CalendarFeedToken
{
    public const SELECTOR_LENGTH = FeedToken::SELECTOR_LENGTH;

    public const SECRET_LENGTH = FeedToken::SECRET_LENGTH;

    public const PATTERN = FeedToken::PATTERN;

    /**
     * Nouveau jeton : [sélecteur, jeton complet, empreinte du secret].
     *
     * @return array{selector: string, token: string, hash: string}
     */
    public static function generate(): array
    {
        return FeedToken::generate();
    }

    /**
     * Abonnement correspondant au jeton, ou null.
     */
    public static function find(#[\SensitiveParameter] string $token): ?CalendarFeed
    {
        $feed = CalendarFeed::query()->where('selector', FeedToken::selector($token))->first();

        return FeedToken::matches($feed?->verifier_hash, $token) ? $feed : null;
    }

    public static function url(#[\SensitiveParameter] string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/compliance/calendar/'.$token.'.ics';
    }
}
