<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Journal des événements de sécurité (canal "security", voir config/logging.php).
 *
 * Chaque entrée contient : le type d'événement (aussi utilisé comme message), l'identifiant de
 * l'utilisateur s'il est connu, l'IP, la date (UTC) et la route. Le contexte ajouté par
 * l'appelant ne doit jamais contenir d'email, de nom, de mot de passe, de jeton ni de code 2FA :
 * pour relier des tentatives visant une même adresse, utiliser emailHash().
 *
 * L'IP est conservée pour relier plusieurs comptes utilisés par une même personne (intérêt
 * légitime de sécurité), avec une conservation limitée à 30 jours.
 */
final class SecurityLog
{
    public const CHANNEL = 'security';

    /**
     * @param  array<string, mixed>  $context  données propres à l'événement (jamais de donnée sensible)
     */
    public static function record(string $event, array $context = [], string $level = 'info', ?Request $request = null): void
    {
        $request ??= request();

        Log::channel(self::CHANNEL)->log($level, $event, [
            'event' => $event,
            'user_id' => array_key_exists('user_id', $context) ? $context['user_id'] : $request->user()?->getAuthIdentifier(),
            'ip' => $request->ip(),
            'route' => $request->method().' /'.ltrim($request->path(), '/'),
            'at' => now()->toIso8601ZuluString(),
            ...collect($context)->except(['user_id'])->all(),
        ]);
    }

    /**
     * Empreinte d'une adresse email (HMAC-SHA256 avec APP_KEY), pour relier des tentatives sur
     * une même adresse, existante ou non, sans l'écrire en clair. Sans APP_KEY, impossible de
     * retrouver l'adresse par force brute sur une liste d'emails.
     */
    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key'));
    }
}
