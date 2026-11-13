<?php

namespace App\Modules\Blog\Support;

use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Models\Article;
use App\Support\Locale;

/**
 * Présentation publique de l'auteur d'un article :
 *
 * - prestataire : son profil public (Action publique GetProviderSummaries du module Accounts,
 *   en une requête pour toute une page) ; slug null si le profil a été dépublié ;
 * - entrepreneur : le nom d'auteur choisi pour l'article (validé avec lui) ;
 * - équipe : le nom de la rédaction (config blog.editorial_name).
 *
 * Jamais d'email, de nom d'utilisateur ni d'identifiant interne.
 *
 * @phpstan-import-type ProviderSummary from GetProviderSummaries
 */
final class AuthorDirectory
{
    /**
     * @param  array<int, ProviderSummary>  $providers  résumés indexés par identifiant d'utilisateur
     */
    private function __construct(private array $providers, private string $locale) {}

    /**
     * @param  iterable<Article>  $articles
     */
    public static function for(iterable $articles, string $locale = Locale::DEFAULT): self
    {
        $providerIds = [];

        foreach ($articles as $article) {
            if ($article->author_kind === AuthorKind::Provider && $article->user_id !== null) {
                $providerIds[] = $article->user_id;
            }
        }

        return new self(app(GetProviderSummaries::class)->handle(array_values(array_unique($providerIds))), $locale);
    }

    /**
     * @return array{kind: string, name: string|null, slug: string|null, headline: string|null, logo_url: string|null}
     */
    public function present(Article $article): array
    {
        $author = ['kind' => $article->author_kind->value, 'name' => null, 'slug' => null, 'headline' => null, 'logo_url' => null];

        return match ($article->author_kind) {
            AuthorKind::Editorial => [...$author, 'name' => self::editorialName($this->locale)],
            AuthorKind::Entrepreneur => [...$author, 'name' => $article->author_name],
            AuthorKind::Provider => $this->provider($article, $author),
        };
    }

    public static function editorialName(string $locale): string
    {
        /** @var array<string, string> $names */
        $names = (array) config('blog.editorial_name');

        return $names[$locale] ?? $names[Locale::DEFAULT] ?? 'Loopartner';
    }

    /**
     * @param  array{kind: string, name: string|null, slug: string|null, headline: string|null, logo_url: string|null}  $author
     * @return array{kind: string, name: string|null, slug: string|null, headline: string|null, logo_url: string|null}
     */
    private function provider(Article $article, array $author): array
    {
        $summary = $article->user_id !== null ? ($this->providers[$article->user_id] ?? null) : null;

        return $summary === null ? $author : [
            ...$author,
            'name' => $summary['display_name'],
            'slug' => $summary['slug'],
            'headline' => $summary['headline'],
            'logo_url' => $summary['logo_url'],
        ];
    }
}
