<?php

namespace App\Modules\Moderation\Support;

use Illuminate\Support\Str;

/**
 * Jeton de suivi d'un signalement fait sans compte : 48 caractères aléatoires (environ
 * 285 bits), remis à la création (réponse et email) puis à la décision (nouveau jeton, l'ancien
 * cesse de fonctionner). Jamais stocké en clair : seule son empreinte HMAC-SHA256 (APP_KEY) est
 * en base, comparée en temps constant. Le lien du frontend le porte dans le fragment
 * (#token=…), qui n'est jamais envoyé aux serveurs ; le frontend l'envoie dans le corps d'une
 * requête POST, jamais dans l'URL.
 */
final class TrackingToken
{
    public const LENGTH = 48;

    /**
     * @return array{0: string, 1: string} [jeton, empreinte]
     */
    public static function generate(): array
    {
        $token = Str::random(self::LENGTH);

        return [$token, self::hash($token)];
    }

    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public static function matches(?string $storedHash, #[\SensitiveParameter] string $token): bool
    {
        return $storedHash !== null && strlen($token) === self::LENGTH && hash_equals($storedHash, self::hash($token));
    }
}
