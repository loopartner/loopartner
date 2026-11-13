<?php

namespace App\Modules\Blog\Http\Requests;

use App\Rules\NoInvisibleCharacters;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /articles : liste publique des articles publiés. Filtres facultatifs (un paramètre vide
 * est ignoré) :
 *
 * - category : slug d'une catégorie (sous-catégories comprises) ;
 * - language : langue configurée (config blog.languages) ;
 * - author   : slug d'un profil prestataire publié, ou "editorial" pour la rédaction ;
 * - q        : 2 à 100 caractères, cherchés dans le titre et le chapô (Like échappé).
 */
class SearchArticlesRequest extends FormRequest
{
    public const EDITORIAL = 'editorial';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...Pagination::rules(),
            'category' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'language' => ['sometimes', 'nullable', 'string', Rule::in((array) config('blog.languages'))],
            'author' => ['sometimes', 'nullable', 'string', 'max:191', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'q' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100', new NoInvisibleCharacters],
        ];
    }
}
