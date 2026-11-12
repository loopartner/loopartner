<?php

namespace App\Modules\Blog\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Models\Media;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Support\ArticleViews;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Article vu par son auteur (/me/articles). Chaque champ est listé : jamais d'identifiant
 * interne (ni id, ni user_id, ni id de média), jamais l'identité d'un modérateur.
 *
 * Le corps (body) n'est donné qu'en détail (detailed()) : la liste reste légère. Il est
 * présenté comme au public (liens avec rel, images avec URL).
 *
 * Relations attendues : categories, cover, pendingRevision.cover, latestRevision.
 *
 * @mixin Article
 */
class OwnArticleResource extends JsonResource
{
    public bool $withBody = false;

    public static function detailed(Article $article): self
    {
        $resource = new self($article);
        $resource->withBody = true;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Media|null $cover */
        $cover = $this->cover;

        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'version' => $this->version,
            'author_kind' => $this->author_kind->value,
            'language' => $this->language,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'body' => $this->when($this->withBody, fn (): array => ArticleViews::body($this->body, $this->author_kind)),
            'author_name' => $this->author_name,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'categories' => ServiceCategoryResource::collection($this->categories),
            'cover' => ArticleViews::cover($cover),
            'word_count' => $this->word_count,
            'reading_time_minutes' => $this->reading_time_minutes,
            'submission_count' => $this->submission_count,
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'content_updated_at' => $this->content_updated_at?->toIso8601ZuluString(),
            'rejected_at' => $this->rejected_at?->toIso8601ZuluString(),
            'rejection_reason' => $this->rejection_reason,
            'unpublished_at' => $this->unpublished_at?->toIso8601ZuluString(),
            'unpublication_reason' => $this->unpublication_reason,
            'pending_revision' => $this->pendingRevisionView(),
            'last_revision_decision' => $this->lastRevisionDecision(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingRevisionView(): ?array
    {
        /** @var ArticleRevision|null $revision */
        $revision = $this->pendingRevision;

        if ($revision === null) {
            return null;
        }

        /** @var Media|null $proposedCover */
        $proposedCover = $revision->cover;

        return [
            'uuid' => $revision->uuid,
            'version' => $revision->version,
            'changed_fields' => $revision->changed_fields,
            'submitted_at' => $revision->submitted_at->toIso8601ZuluString(),
            'cover' => $revision->coverOperation(),
            'proposed_cover' => ArticleViews::cover($proposedCover),
            'content' => $this->when($this->withBody, function () use ($revision): array {
                $content = $revision->content();
                /** @var array<string, mixed>|null $body */
                $body = is_array($content['body'] ?? null) ? $content['body'] : null;
                $content['body'] = ArticleViews::body($body, $this->author_kind);

                return $content;
            }),
        ];
    }

    /**
     * Dernière décision sur une révision (refus avec motif), pour l'afficher à l'auteur.
     *
     * @return array{status: string, rejection_reason: string|null, decided_at: string|null}|null
     */
    private function lastRevisionDecision(): ?array
    {
        /** @var ArticleRevision|null $revision */
        $revision = $this->latestRevision;

        if ($revision === null || $revision->status === RevisionStatus::Pending) {
            return null;
        }

        return [
            'status' => $revision->status->value,
            'rejection_reason' => $revision->rejection_reason,
            'decided_at' => $revision->decided_at?->toIso8601ZuluString(),
        ];
    }
}
