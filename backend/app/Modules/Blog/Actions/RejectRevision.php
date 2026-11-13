<?php

namespace App\Modules\Blog\Actions;

use App\Models\User;
use App\Modules\Blog\Enums\ArticleNotice;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Notifications\ArticleNotification;
use App\Modules\Blog\Support\BlogModeration;
use App\Modules\Blog\Support\PendingRevisions;
use App\Support\AdminAudit;
use App\Support\Moderation\VersionGuard;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Refus d'une révision, avec un motif communiqué à l'auteur. La version publiée reste en
 * ligne ; les images et la couverture proposées sont supprimées.
 */
class RejectRevision
{
    /**
     * @throws AccessDeniedHttpException
     * @throws ConflictHttpException
     */
    public function handle(User $moderator, ArticleRevision $revision, int $version, string $reason): ArticleRevision
    {
        return DB::transaction(function () use ($moderator, $revision, $version, $reason): ArticleRevision {
            $article = Article::query()->whereKey($revision->article_id)->lockForUpdate()->firstOrFail();
            $revision = ArticleRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();

            BlogModeration::assertNotOwn($moderator, $article->user_id, $article->uuid);

            if (! $revision->isPending()) {
                throw new ConflictHttpException('Cette modification n\'est plus en attente de validation.');
            }

            VersionGuard::assertSame($version, $revision->version);

            PendingRevisions::close($revision, RevisionStatus::Rejected, $moderator->id, $reason);

            AdminAudit::record($moderator, 'blog.revision.rejected', 'article_revision', $revision->uuid, $revision->version, $reason);
            SecurityLog::record('blog.revision_rejected', ['user_id' => $moderator->id, 'article' => $article->uuid, 'revision' => $revision->uuid]);

            ArticleNotification::sendTo($article->user, $article, ArticleNotice::RevisionRejected, $reason);

            return $revision;
        });
    }
}
