<?php

namespace App\Modules\Blog\Support;

use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Rules\ValidArticleBody;
use App\Rules\ActiveServiceCategory;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeLinksInText;
use Illuminate\Validation\Rule;

/**
 * Règles de validation d'un article, partagées par la création (POST) et la modification
 * partielle (PATCH). La complétude (chapô, corps assez long, catégorie…) n'est exigée qu'à la
 * soumission (ArticleContent::assertComplete) : un brouillon peut être incomplet.
 *
 * Les champs fixés par la plateforme (statut, slug, version, auteur, compteurs, dates du cycle
 * de vie…) sont refusés (prohibited) : un auteur ne publie jamais lui-même son article.
 */
final class ArticleRules
{
    /** @var list<string> */
    public const PROHIBITED = [
        'id', 'uuid', 'slug', 'status', 'version', 'user_id', 'author_kind', 'word_count',
        'reading_time_minutes', 'submission_count', 'first_submitted_at', 'submitted_at',
        'published_at', 'content_updated_at', 'rejected_at', 'rejection_reason', 'unpublished_at',
        'unpublication_reason', 'cover_media_id', 'created_at', 'updated_at', 'premium',
    ];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $partial, AuthorKind $kind): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $optional = $partial ? ['sometimes', 'nullable'] : ['nullable'];
        $text = [new NoHtml, new NoInvisibleCharacters, new SafeLinksInText];

        $rules = [
            'title' => [...$required, 'string', 'min:10', 'max:160', ...$text],
            'excerpt' => [...$optional, 'string', 'min:50', 'max:300', ...$text],
            'body' => [...$optional, 'array', new ValidArticleBody],
            'language' => [...$required, 'string', Rule::in((array) config('blog.languages'))],
            'categories' => [...$optional, 'array', 'list', 'max:'.(int) config('blog.max_categories')],
            'categories.*' => ['required', 'string', 'distinct', 'max:100', new ActiveServiceCategory],
            // Seul un entrepreneur signe d'un nom : un prestataire est présenté par son profil
            // public, l'équipe par le nom de la rédaction.
            'author_name' => $kind === AuthorKind::Entrepreneur
                ? [...$optional, 'string', 'min:2', 'max:80', ...$text]
                : ['prohibited'],
            'seo_title' => [...$optional, 'string', 'min:10', 'max:70', ...$text],
            'seo_description' => [...$optional, 'string', 'min:50', 'max:160', ...$text],
            'cover' => [...$optional, 'string', 'uuid'],
        ];

        foreach (self::PROHIBITED as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
