<?php

namespace App\Modules\Blog\Support;

use App\Models\ServiceCategory;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Models\Article;
use App\Support\Database\JsonDocument;
use Illuminate\Validation\ValidationException;

/**
 * Contenu d'un article sous forme de tableau : celui de l'article publié, d'un brouillon ou
 * d'une révision proposée. Partagé par la modification directe, les révisions et la
 * modération (comparaison, complétude, application).
 *
 * Champs : title, excerpt, body (document normalisé), language, author_name, seo_title,
 * seo_description, categories (slugs, triés).
 */
final class ArticleContent
{
    /** @var list<string> */
    public const FIELDS = ['title', 'excerpt', 'body', 'language', 'author_name', 'seo_title', 'seo_description', 'categories'];

    /**
     * @return array<string, mixed>
     */
    public static function of(Article $article): array
    {
        return [
            'title' => $article->title,
            'excerpt' => $article->excerpt,
            'body' => $article->body ?? ArticleBody::empty(),
            'language' => $article->language,
            'author_name' => $article->author_name,
            'seo_title' => $article->seo_title,
            'seo_description' => $article->seo_description,
            'categories' => $article->categories()->pluck('slug')->sort()->values()->all(),
        ];
    }

    /**
     * Contenu vide d'un nouvel article.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return array_fill_keys(self::FIELDS, null);
    }

    /**
     * Champs de contenu des données validées, avec le corps normalisé (document reconstruit par
     * ArticleBody, jamais l'entrée brute). null pour le corps : document vide.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromInput(array $data): array
    {
        $content = array_intersect_key($data, array_flip(self::FIELDS));

        if (array_key_exists('body', $content)) {
            $content['body'] = $content['body'] === null ? ArticleBody::empty() : ArticleBody::parse($content['body']);
        }

        if (array_key_exists('categories', $content) && $content['categories'] === null) {
            $content['categories'] = [];
        }

        return $content;
    }

    /**
     * Applique les champs envoyés (données validées, corps déjà normalisé) au contenu de base.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $data): array
    {
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $base[$field] = $data[$field];
            }
        }

        $base['body'] = is_array($base['body'] ?? null) ? $base['body'] : ArticleBody::empty();
        $categories = is_array($base['categories'] ?? null) ? $base['categories'] : [];
        // sort() réindexe : la liste est comparable entre deux versions (changedFields).
        sort($categories);
        $base['categories'] = $categories;

        return $base;
    }

    /**
     * Noms des champs dont la valeur diffère. Le corps lu en base (colonne JSON) n'a pas
     * forcément l'ordre de clés du corps reconstruit (MySQL les trie) : comparaison canonique.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function changedFields(array $before, array $after): array
    {
        return array_values(array_filter(
            self::FIELDS,
            fn (string $field): bool => ! JsonDocument::same($before[$field] ?? null, $after[$field] ?? null),
        ));
    }

    /**
     * Vérifie qu'un contenu peut être soumis (ou publié) : champs obligatoires, corps assez long,
     * au moins une catégorie, nom d'auteur pour un entrepreneur seulement.
     *
     * @param  array<string, mixed>  $content
     *
     * @throws ValidationException
     */
    public static function assertComplete(array $content, AuthorKind $kind): void
    {
        $errors = [];

        foreach (['title', 'excerpt', 'language'] as $field) {
            if (! is_string($content[$field] ?? null) || trim($content[$field]) === '') {
                $errors[$field] = 'Ce champ est obligatoire pour soumettre l\'article.';
            }
        }

        $minWords = (int) config('blog.min_words');
        $body = is_array($content['body'] ?? null) ? $content['body'] : null;

        if (ArticleBody::wordCount($body) < $minWords) {
            $errors['body'] = "Le corps de l'article doit compter au moins {$minWords} mots.";
        }

        if (! is_array($content['categories'] ?? null) || $content['categories'] === []) {
            $errors['categories'] = 'Choisissez au moins une catégorie.';
        }

        if ($kind === AuthorKind::Entrepreneur && (! is_string($content['author_name'] ?? null) || trim($content['author_name']) === '')) {
            $errors['author_name'] = 'Indiquez le nom sous lequel l\'article sera signé.';
        }

        if ($kind !== AuthorKind::Entrepreneur && ($content['author_name'] ?? null) !== null) {
            $errors['author_name'] = 'Ce type de compte ne choisit pas de nom d\'auteur.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Écrit le contenu sur l'article (sans l'enregistrer) et recalcule les mots et le temps de
     * lecture. Les catégories sont synchronisées à part (categoryIds()), après l'enregistrement.
     *
     * @param  array<string, mixed>  $content
     */
    public static function apply(Article $article, array $content): void
    {
        /** @var array{version: int, blocks: list<array<string, mixed>>} $body */
        $body = is_array($content['body'] ?? null) ? $content['body'] : ArticleBody::empty();
        $words = ArticleBody::wordCount($body);

        $article->forceFill([
            'title' => $content['title'],
            'excerpt' => $content['excerpt'] ?? null,
            'body' => $body,
            'language' => $content['language'],
            'author_name' => $content['author_name'] ?? null,
            'seo_title' => $content['seo_title'] ?? null,
            'seo_description' => $content['seo_description'] ?? null,
            'word_count' => $words,
            'reading_time_minutes' => ArticleBody::readingMinutes($words),
        ]);
    }

    /**
     * Identifiants des catégories du contenu.
     *
     * @param  array<string, mixed>  $content
     * @return list<int>
     */
    public static function categoryIds(array $content): array
    {
        $slugs = is_array($content['categories'] ?? null) ? $content['categories'] : [];

        /** @var list<int> */
        return ServiceCategory::query()->whereIn('slug', $slugs)->pluck('id')->all();
    }
}
