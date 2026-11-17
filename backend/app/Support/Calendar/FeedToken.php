<?php

namespace App\Support\Calendar;

use Illuminate\Support\Str;

/**
 * Jeton d'une URL privée d'abonnement à un calendrier (brique du noyau, utilisée par
 * l'abonnement à l'agenda et par celui du suivi administratif) : 16 caractères de sélecteur
 * (publics, pour retrouver la ligne par un index) suivis de 40 caractères secrets (≈ 238 bits).
 *
 * Seule l'empreinte HMAC-SHA256 (APP_KEY) du secret est stockée, et la comparaison se fait en
 * temps constant (hash_equals). Un sélecteur inconnu donne lieu au même calcul, pour que le
 * temps de réponse ne révèle pas qu'il existe. Le jeton n'est jamais journalisé.
 */
final class FeedToken
{
    public const SELECTOR_LENGTH = 16;

    public const SECRET_LENGTH = 40;

    public const PATTERN = '[A-Za-z0-9]{56}';

    /**
     * Nouveau jeton : sélecteur, jeton complet et empreinte du secret.
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
     * Sélecteur (partie publique) d'un jeton reçu.
     */
    public static function selector(#[\SensitiveParameter] string $token): string
    {
        return substr($token, 0, self::SELECTOR_LENGTH);
    }

    /**
     * Le secret du jeton correspond à l'empreinte stockée. Sans empreinte (sélecteur inconnu),
     * le même calcul est fait et le résultat est faux.
     */
    public static function matches(?string $storedHash, #[\SensitiveParameter] string $token): bool
    {
        $secret = substr($token, self::SELECTOR_LENGTH);
        $expected = $storedHash ?? self::hash(str_repeat('0', self::SECRET_LENGTH));
        $valid = hash_equals($expected, self::hash($secret));

        return $storedHash !== null && $valid;
    }

    private static function hash(#[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) config('app.key'));
    }
}
