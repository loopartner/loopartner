<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toute requête sur api/* est traitée comme une requête JSON, même sans en-tête
 * "Accept: application/json".
 *
 * Sans cela, les middlewares de Laravel (auth, verified, password.confirm) tentent une
 * redirection vers une page (login, verification.notice, password.confirm) qui n'existe pas
 * dans cette API : erreur 500 au lieu de 401, 403 ou 423.
 */
class ForceJsonOnApi
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
