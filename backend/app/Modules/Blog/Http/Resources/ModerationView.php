<?php

namespace App\Modules\Blog\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Models\AdminAuditLog;
use App\Models\Media;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Support\ArticleContent;
use App\Modules\Blog\Support\ArticleLinks;
use App\Modules\Blog\Support\ArticleViews;
use App\Modules\Blog\Support\AuthorDirectory;
use App\Modules\Blog\Support\AuthorHistory;
use App\Support\AdminAudit;

/**
 * Vues de la modération du Blog (rédacteurs et admins avec la 2FA de session) : contenu
 * complet de l'élément à vérifier, liens et domaines mis en évidence, historique de l'auteur et
 * décisions déjà prises.
 *
 * - own : l'élément appartient au modérateur connecté (il ne peut pas le modérer).
 * - Aucun email, aucun identifiant interne d'utilisateur ; un modérateur est désigné par son
 *   seul rôle dans l'historique des décisions.
 */
final class ModerationView
{
    /**
     * @return array<string, mixed>
     */
    public static function article(Article $article, User $moderator): array
    {
        $content = ArticleContent::of($article);
        $revision = $article->pendingRevision;

        return [
            'uuid' => $article->uuid,
            'slug' => $article->slug,
            'status' => $article->status->value,
            'version' => $article->version,
            'own' => $article->user_id === $moderator->id,
            'author' => AuthorDirectory::for([$article])->present($article),
            'submission_count' => $article->submission_count,
            'submitted_at' => $article->submitted_at?->toIso8601ZuluString(),
            'published_at' => $article->published_at?->toIso8601ZuluString(),
            'content' => self::content($content, $article, $article->categories->all()),
            ...ArticleLinks::inspect($content, $article->user_id, $article->id),
            'rejection_reason' => $article->rejection_reason,
            'unpublication_reason' => $article->unpublication_reason,
            'pending_revision' => $revision instanceof ArticleRevision ? [
                'uuid' => $revision->uuid,
                'version' => $revision->version,
                'submitted_at' => $revision->submitted_at->toIso8601ZuluString(),
                'changed_fields' => $revision->changed_fields,
            ] : null,
            'author_history' => app(AuthorHistory::class)->for($article->user),
            'decisions' => self::decisions('article', $article->uuid),
        ];
    }

    /**
     * Révision : version proposée et version publiée, côte à côte.
     *
     * @return array<string, mixed>
     */
    public static function revision(ArticleRevision $revision, User $moderator): array
    {
        $article = $revision->article;
        $proposed = ArticleContent::merge(ArticleContent::blank(), $revision->content());
        $proposedCategories = ServiceCategory::query()->whereIn('slug', (array) $proposed['categories'])->get()->all();

        /** @var Media|null $proposedCover */
        $proposedCover = $revision->cover;

        return [
            'uuid' => $revision->uuid,
            'status' => $revision->status->value,
            'version' => $revision->version,
            'own' => $article->user_id === $moderator->id,
            'changed_fields' => $revision->changed_fields,
            'submitted_at' => $revision->submitted_at->toIso8601ZuluString(),
            'decided_at' => $revision->decided_at?->toIso8601ZuluString(),
            'rejection_reason' => $revision->rejection_reason,
            'article' => [
                'uuid' => $article->uuid,
                'slug' => $article->slug,
                'status' => $article->status->value,
                'version' => $article->version,
                'author' => AuthorDirectory::for([$article])->present($article),
            ],
            'proposed' => [
                ...self::content($proposed, $article, $proposedCategories),
                'cover_operation' => $revision->coverOperation(),
                'proposed_cover' => ArticleViews::cover($proposedCover),
            ],
            'current' => self::content(ArticleContent::of($article), $article, $article->categories->all()),
            ...ArticleLinks::inspect($proposed, $article->user_id, $article->id),
            'author_history' => app(AuthorHistory::class)->for($article->user),
            'decisions' => self::decisions('article_revision', $revision->uuid),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<ServiceCategory>  $categories
     * @return array<string, mixed>
     */
    private static function content(array $content, Article $article, array $categories): array
    {
        /** @var Media|null $cover */
        $cover = $article->cover;
        /** @var array<string, mixed>|null $body */
        $body = is_array($content['body'] ?? null) ? $content['body'] : null;

        return [
            'title' => $content['title'],
            'excerpt' => $content['excerpt'],
            'language' => $content['language'],
            'author_name' => $content['author_name'],
            'seo_title' => $content['seo_title'],
            'seo_description' => $content['seo_description'],
            'categories' => ServiceCategoryResource::collection($categories)->resolve(),
            'cover' => ArticleViews::cover($cover),
            'body' => ArticleViews::body($body, $article->author_kind),
        ];
    }

    /**
     * Décisions déjà prises sur l'élément (journal d'audit), sans identité du modérateur.
     *
     * @return list<array{action: string, version: int|null, reason: string|null, at: string|null}>
     */
    private static function decisions(string $type, string $uuid): array
    {
        return AdminAudit::historyOf($type, $uuid)
            ->map(fn (AdminAuditLog $entry): array => [
                'action' => $entry->action,
                'version' => $entry->version,
                'reason' => $entry->reason,
                'at' => $entry->created_at?->toIso8601ZuluString(),
            ])
            ->values()
            ->all();
    }
}
