<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique un limiteur nommé aux routes d'authentification de Fortify qui n'en ont pas.
 *
 * Fortify ne permet de configurer que les limiteurs de la connexion et du défi 2FA. Ce middleware est ajouté au groupe des routes Fortify
 * (config/fortify.php, clé "middleware") et choisit le limiteur d'après le nom de la route.
 * Les limiteurs sont déclarés dans AppServiceProvider, leurs valeurs dans config/security.php.
 */
class ThrottleAuthRoutes
{
    /**
     * Nom de route Fortify => limiteur nommé.
     *
     * @var array<string, string>
     */
    public const LIMITERS = [
        'password.email' => 'forgot-password',
        'password.update' => 'reset-password',
        'user-password.update' => 'password-update',
        'password.confirm.store' => 'password-confirm',
    ];

    public function __construct(private ThrottleRequests $throttle) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limiter = self::LIMITERS[$request->route()?->getName() ?? ''] ?? null;

        if ($limiter === null) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, $limiter);
    }
}
