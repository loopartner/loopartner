<?php

namespace App\Modules\Blog\Actions;

use App\Models\Media;
use App\Models\User;
use App\Modules\Blog\Enums\ArticleNotice;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Notifications\ArticleNotification;
use App\Modules\Blog\Support\ArticleContent;
use App\Modules\Blog\Support\ArticleImages;
use App\Modules\Blog\Support\BlogModeration;
use App\Support\AdminAudit;
use App\Support\Media\MediaLibrary;
use App\Support\Moderation\VersionGuard;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Validation d'une révision : la version proposée remplace la version publiée (contenu,
 * catégories, images, couverture), la version de l'article augmente, sa date de mise à jour
 * aussi, et l'auteur est prévenu. Le slug ne change pas.
 *
 * 403 sur sa propre révision ; 409 si la révision n'est plus en attente, a changé depuis la
 * lecture du modérateur, si l'article n'est plus publié, si l'auteur ne peut plus publier ou
 * si la version proposée n'est plus valide (catégorie désactivée…).
 */
class ApproveRevision
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @throws AccessDeniedHttpException
     * @throws ConflictHttpException
     */
    public function handle(User $moderator, ArticleRevision $revision, int $version): ArticleRevision
    {
        return DB::transaction(function () use ($moderator, $revision, $version): ArticleRevision {
            $article = Article::query()->whereKey($revision->article_id)->lockForUpdate()->firstOrFail();
            $revision = ArticleRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();

            BlogModeration::assertNotOwn($moderator, $article->user_id, $article->uuid);

            if (! $revision->isPending()) {
                throw new ConflictHttpException('Cette modification n\'est plus en attente de validation.');
            }

            VersionGuard::assertSame($version, $revision->version);

            if (! $article->isPublished()) {
                throw new ConflictHttpException('Cet article n\'est plus publié : la modification ne peut plus être appliquée.');
            }

            $author = BlogModeration::assertAuthorEligible($article);
            $content = ArticleContent::merge(ArticleContent::blank(), $revision->content());

            try {
                ArticleContent::assertComplete($content, $article->author_kind);
            } catch (ValidationException $e) {
                throw new ConflictHttpException('La version proposée n\'est plus valide : '.collect($e->errors())->flatten()->first());
            }

            ArticleContent::apply($article, $content);
            $this->applyCover($article, $revision);
            $article->forceFill(['version' => $article->version + 1, 'content_updated_at' => now()])->save();
            $article->categories()->sync(ArticleContent::categoryIds($content));

            /** @var array<string, mixed> $body */
            $body = $content['body'];
            ArticleImages::applyRevision($revision, $article, $body);

            $revision->forceFill([
                'status' => RevisionStatus::Approved,
                'pending_key' => null,
                'decided_at' => now(),
                'decided_by' => $moderator->id,
            ])->save();

            AdminAudit::record($moderator, 'blog.revision.approved', 'article_revision', $revision->uuid, $revision->version);
            SecurityLog::record('blog.revision_approved', ['user_id' => $moderator->id, 'article' => $article->uuid, 'revision' => $revision->uuid]);

            ArticleNotification::sendTo($author, $article, ArticleNotice::RevisionApproved);

            return $revision;
        });
    }

    /**
     * Applique l'opération proposée sur la couverture : l'ancienne couverture est supprimée
     * (ligne et fichier) quand elle est remplacée ou retirée.
     */
    private function applyCover(Article $article, ArticleRevision $revision): void
    {
        $operation = $revision->coverOperation();

        if ($operation === ArticleRevision::COVER_KEEP) {
            return;
        }

        $previous = $article->cover_media_id !== null ? Media::query()->whereKey($article->cover_media_id)->first() : null;
        $replacement = $operation === ArticleRevision::COVER_REPLACE ? $revision->cover_media_id : null;

        $article->forceFill(['cover_media_id' => $replacement]);
        $revision->forceFill(['cover_media_id' => null]);
        $article->save();
        $revision->save();

        if ($previous !== null && $previous->id !== $replacement) {
            $this->library->delete($previous);
        }
    }
}
