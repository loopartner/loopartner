<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Pagination des listes de l'API : paramètres "page" et "per_page" (20 par défaut, 50 au
 * maximum ; au-delà, 422).
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * Nombre de résultats par page, à appeler après la validation.
     */
    public static function perPage(Request $request): int
    {
        return $request->integer('per_page', self::DEFAULT_PER_PAGE);
    }
}
