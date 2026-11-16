<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Legal\LegalDocuments;
use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque un utilisateur connecté qui n'a pas accepté la version en vigueur des CGU ou de la
 * politique de confidentialité : 403 {"message", "code": "consent_required", "documents"}.
 *
 * Ajouté au groupe "api" (toutes les routes du noyau et des modules) et placé après
 * l'authentification (bootstrap/app.php). Ne concerne que les routes authentifiées : les
 * pages publiques restent lisibles. Toujours permis : lire les documents, consulter et
 * enregistrer son acceptation, supprimer son compte. La déconnexion (Fortify) n'est pas dans
 * le groupe "api".
 */
class EnsureLegalConsent
{
    /** Routes permises sans acceptation (vérifiées par un test). */
    public const ALLOWED_ROUTES = [
        'legal.documents.index',
        'legal.documents.show',
        'legal.me.show',
        'legal.me.accept',
        'accounts.me.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $user = $request->user();

        if (! $user instanceof User || $route === null || ! $this->requiresAuthentication($route->gatherMiddleware())) {
            return $next($request);
        }

        if (in_array($route->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        $pending = LegalDocuments::pendingFor($user);

        if ($pending === []) {
            return $next($request);
        }

        return response()->json(LegalDocuments::consentRequiredPayload($pending, Locale::fromRequest($request)), 403);
    }

    /**
     * @param  array<int, mixed>  $middleware
     */
    private function requiresAuthentication(array $middleware): bool
    {
        foreach ($middleware as $name) {
            if (is_string($name) && ($name === 'auth' || str_starts_with($name, 'auth:'))) {
                return true;
            }
        }

        return false;
    }
}
