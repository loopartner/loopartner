<?php

namespace App\Support\RateLimit;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limite de débit comptée dans une Action (et non par un middleware de route) : par exemple
 * les révisions d'un contenu publié, qui passent par un endpoint de modification plus général.
 *
 * Plusieurs fenêtres (heure, jour…) : la tentative n'est comptée dans aucune fenêtre si l'une
 * d'elles est déjà pleine. Au-delà : 429 avec Retry-After (journalisé comme les autres 429).
 */
final class ActionThrottle
{
    /**
     * @param  array<string, array{0: int, 1: int}>  $windows  fenêtre => [maximum, durée en secondes]
     *
     * @throws ThrottleRequestsException
     */
    public static function hit(string $name, int|string $key, array $windows): void
    {
        foreach ($windows as $window => [$max]) {
            $limiterKey = "{$name}:{$window}:{$key}";

            if (RateLimiter::tooManyAttempts($limiterKey, $max)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => RateLimiter::availableIn($limiterKey)]);
            }
        }

        foreach ($windows as $window => [, $decay]) {
            RateLimiter::hit("{$name}:{$window}:{$key}", $decay);
        }
    }
}
