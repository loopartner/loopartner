<?php

namespace App\Modules\Blog\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Models\Media;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Support\ArticleViews;
use App\Modules\Blog\Support\AuthorDirectory;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Article publié, tel que le public le voit. Chaque champ est listé : jamais d'identifiant
 * interne, d'uuid, d'email, de nom d'utilisateur, de motif de modération ni de donnée de
 * cycle de vie autre que les dates de publication et de mise à jour.
 *
 * - Résumé (listes, articles liés) : slug, titre, chapô, langue, couverture, catégories, auteur,
 *   temps de lecture, dates.
 * - Détail (detailed()) : en plus, le corps présenté (liens avec leur rel, images avec URL) et
 *   les champs de référencement.
 *
 * L'auteur est présenté par AuthorDirectory (profil prestataire, nom d'auteur d'un
 * entrepreneur, ou nom de la rédaction) : à fournir avec withAuthors() pour toute une page.
 * Relations attendues : categories, cover.
 *
 * @mixin Article
 */
class PublicArticleResource extends JsonResource
{
    public bool $withBody = false;

    public ?AuthorDirectory $authors = null;

    public static function detailed(Article $article, AuthorDirectory $authors): self
    {
        $resource = new self($article);
        $resource->withBody = true;
        $resource->authors = $authors;

        return $resource;
    }

    public static function summary(Article $article, AuthorDirectory $authors): self
    {
        $resource = new self($article);
        $resource->authors = $authors;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Media|null $cover */
        $cover = $this->cover;
        $cover = ArticleViews::cover($cover);
        $authors = $this->authors ?? AuthorDirectory::for([$this->resource], Locale::fromRequest($request));

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'language' => $this->language,
            'cover' => $cover === null ? null : ['url' => $cover['url'], 'width' => $cover['width'], 'height' => $cover['height']],
            'categories' => ServiceCategoryResource::collection($this->categories),
            'author' => $authors->present($this->resource),
            'reading_time_minutes' => $this->reading_time_minutes,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'updated_at' => ($this->content_updated_at ?? $this->published_at)?->toIso8601ZuluString(),
            'seo' => $this->when($this->withBody, fn (): array => [
                'title' => $this->seo_title ?? $this->title,
                'description' => $this->seo_description ?? $this->excerpt,
            ]),
            'body' => $this->when($this->withBody, fn (): array => ArticleViews::body($this->body, $this->author_kind)),
        ];
    }
}
