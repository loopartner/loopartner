<?php

namespace App\Modules\Blog\Actions;

use App\Models\Media;
use App\Models\User;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Support\ArticleContent;
use App\Modules\Blog\Support\ArticleImages;
use App\Support\AdminAudit;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Modification d'un article par son auteur.
 *
 * - Brouillon, refusé ou dépublié : modification directe (la version augmente si quelque chose
 *   change).
 * - En attente de validation : 409, l'auteur retire d'abord sa soumission.
 * - Publié, auteur extérieur à l'équipe : révision en attente de validation (SubmitRevision),
 *   la version publique ne change pas.
 * - Publié, article de l'équipe : modification directe, avec la 2FA de session (vérifiée par le
 *   contrôleur), tracée dans le journal d'audit.
 */
class UpdateArticle
{
    public function __construct(
        private MediaLibrary $library,
        private SubmitRevision $submitRevision,
    ) {}

    /**
     * @param  array<string, mixed>  $data  données validées (champs présents uniquement)
     * @return array{article: Article, revision: ArticleRevision|null}
     *
     * @throws ConflictHttpException
     */
    public function handle(User $author, Article $article, array $data): array
    {
        return DB::transaction(function () use ($author, $article, $data): array {
            $article = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();

            if ($article->status === ArticleStatus::PendingReview) {
                throw new ConflictHttpException('Cet article est en attente de validation : retirez-le de la validation avant de le modifier.');
            }

            if ($article->isPublished() && ! $article->author_kind->isEditorial()) {
                return ['article' => $article, 'revision' => $this->submitRevision->handle($author, $article, $data)];
            }

            $this->applyDirectly($author, $article, $data);

            return ['article' => $article, 'revision' => null];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyDirectly(User $author, Article $article, array $data): void
    {
        $before = ArticleContent::of($article);
        $content = ArticleContent::merge($before, ArticleContent::fromInput($data));
        $changed = ArticleContent::changedFields($before, $content);

        if (array_key_exists('cover', $data) && $this->replaceCover($author, $article, $data['cover'])) {
            $changed[] = 'cover';
        }

        if ($changed === []) {
            return;
        }

        ArticleContent::apply($article, $content);
        $article->forceFill([
            'version' => $article->version + 1,
            'content_updated_at' => $article->isPublished() ? now() : $article->content_updated_at,
        ])->save();
        $article->categories()->sync(ArticleContent::categoryIds($content));

        /** @var array<string, mixed> $body */
        $body = $content['body'];
        ArticleImages::syncArticle($author, $article, $body);

        if ($article->isPublished()) {
            AdminAudit::record($author, 'blog.article.updated_directly', 'article', $article->uuid, $article->version);
            SecurityLog::record('blog.updated_directly', [
                'user_id' => $author->id,
                'article' => $article->uuid,
                'changed_fields' => $changed,
            ]);
        }
    }

    /**
     * Remplace ou retire la couverture. Renvoie true si elle change.
     */
    private function replaceCover(User $author, Article $article, mixed $uuid): bool
    {
        $uuid = is_string($uuid) ? $uuid : null;
        $currentUuid = $article->cover_media_id !== null ? Media::query()->whereKey($article->cover_media_id)->value('uuid') : null;

        if ($uuid === $currentUuid) {
            return false;
        }

        $article->forceFill([
            'cover_media_id' => $this->library->replace($author, MediaPurpose::ArticleCover, $uuid, $article->cover_media_id, 'cover'),
        ]);

        return true;
    }
}
