<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Models\CalendarFeed;
use Illuminate\Support\Str;

/**
 * Jeton de l'URL privée d'abonnement au calendrier : 16 caractères de sélecteur (publics, pour
 * retrouver la ligne par un index) suivis de 40 caractères secrets (≈ 238 bits).
 *
 * Seule l'empreinte HMAC-SHA256 (APP_KEY) du secret est stockée, et la comparaison se fait en
 * temps constant (hash_equals). Un sélecteur inconnu donne lieu au même calcul, pour que le
 * temps de réponse ne révèle pas qu'il existe. Le jeton n'est jamais journalisé.
 */
final class CalendarFeedToken
{
    public const SELECTOR_LENGTH = 16;

    public const SECRET_LENGTH = 40;

    public const PATTERN = '[A-Za-z0-9]{56}';

    /**
     * Nouveau jeton : [sélecteur, jeton complet, empreinte du secret].
     *
     * @return array{selector: string, token: string, hash: string}
     */
    public static function generate(): array
    {
        $selector = Str::random(self::SELECTOR_LENGTH);
        $secret = Str::random(self::SECRET_LENGTH);

        return ['selector' => $selector, 'token' => $selector.$secret, 'hash' => self::hash($secret)];
    }

    /**
     * Abonnement correspondant au jeton, ou null.
     */
    public static function find(#[\SensitiveParameter] string $token): ?CalendarFeed
    {
        $selector = substr($token, 0, self::SELECTOR_LENGTH);
        $secret = substr($token, self::SELECTOR_LENGTH);
        $feed = CalendarFeed::query()->where('selector', $selector)->first();

        // Même calcul et même comparaison, que le sélecteur existe ou non.
        $expected = $feed !== null ? $feed->verifier_hash : self::hash(str_repeat('0', self::SECRET_LENGTH));
        $valid = hash_equals($expected, self::hash($secret));

        return $feed !== null && $valid ? $feed : null;
    }

    public static function url(#[\SensitiveParameter] string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/compliance/calendar/'.$token.'.ics';
    }

    private static function hash(#[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) config('app.key'));
    }
}
