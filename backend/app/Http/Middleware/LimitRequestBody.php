<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse (413) une requête dont le corps dépasse la taille donnée en octets, avant toute
 * validation : protège les endpoints qui reçoivent un document JSON (corps d'un article…)
 * contre les contenus énormes, coûteux à analyser.
 *
 * Usage : LimitRequestBody::class.':262144'.
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next, string $maxBytes): Response
    {
        $max = (int) $maxBytes;
        $declared = $request->headers->get('Content-Length');

        if ((is_numeric($declared) && (int) $declared > $max) || strlen($request->getContent()) > $max) {
            abort(413, sprintf('Le contenu envoyé est trop volumineux (%d Ko au maximum).', intdiv($max, 1024)));
        }

        return $next($request);
    }
}
