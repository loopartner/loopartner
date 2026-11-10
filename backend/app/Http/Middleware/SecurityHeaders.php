<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * En-têtes de sécurité ajoutés à chaque réponse de l'API.
     *
     * L'API ne sert que du JSON : la CSP interdit tout chargement de ressource et tout cadre. Le
     * frontend est sur un autre sous-domaine du même site : Cross-Origin-Resource-Policy vaut
     * same-site (les appels CORS du frontend restent régis par config/cors.php).
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-site',
    ];

    /**
     * HSTS : uniquement en production et sur une requête HTTPS (un en-tête HSTS reçu en HTTP
     * est ignoré par les navigateurs, et en local il bloquerait le HTTP pendant un an).
     * Pas de "preload" : l'inscription dans les navigateurs est difficile à annuler.
     */
    private const HSTS = 'max-age=31536000; includeSubDomains';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        return $response;
    }
}
