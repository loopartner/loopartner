<?php

namespace App\Modules\Blog\Actions;

use App\Models\Media;
use App\Models\User;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Support\ArticleContent;
use App\Modules\Blog\Support\ArticleImages;
use App\Modules\Blog\Support\PendingRevisions;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use App\Support\RateLimit\ActionThrottle;
use App\Support\SecurityLog;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Modification d'un article publié d'un auteur extérieur à l'équipe : une révision en attente
 * de validation. Le public continue de voir la version validée ; la nouvelle version ne la
 * remplace qu'après l'accord d'un modérateur (ApproveRevision). Tous les champs sont concernés,
 * liens, images et couverture compris.
 *
 * - Au plus une révision en attente par article : une nouvelle modification la remplace
 *   (version + 1, retour en fin de file). Les modifications successives s'accumulent.
 * - La version proposée doit être complète (ArticleContent::assertComplete).
 * - Si elle ne diffère plus de la version publiée, la révision en attente est retirée et rien
 *   n'est créé (renvoie null).
 * - Couverture : "cover" absent garde la proposition précédente ; l'uuid de la couverture
 *   actuelle la conserve ; null propose de la retirer ; un autre uuid propose de la remplacer.
 * - Limite de débit article_revisions (par auteur, 429).
 *
 * À appeler dans une transaction, sur un article verrouillé et publié.
 */
class SubmitRevision
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @param  array<string, mixed>  $data  données validées (champs présents uniquement)
     *
     * @throws ValidationException
     * @throws ThrottleRequestsException
     */
    public function handle(User $author, Article $article, array $data): ?ArticleRevision
    {
        ActionThrottle::hit('article-revisions', $author->id, [
            'hour' => [(int) config('security.rate_limits.article_revisions.per_hour'), 3600],
            'day' => [(int) config('security.rate_limits.article_revisions.per_day'), 86400],
        ]);

        $pending = ArticleRevision::query()
            ->where('article_id', $article->id)
            ->where('status', RevisionStatus::Pending->value)
            ->lockForUpdate()
            ->first();

        $published = ArticleContent::of($article);
        $base = $pending !== null ? ArticleContent::merge(ArticleContent::blank(), $pending->content()) : $published;
        $content = ArticleContent::merge($base, ArticleContent::fromInput($data));

        ArticleContent::assertComplete($content, $article->author_kind);

        $revision = $pending ?? new ArticleRevision;
        $cover = $this->coverOperation($author, $article, $revision, $data);
        $changed = ArticleContent::changedFields($published, $content);

        if ($cover !== ArticleRevision::COVER_KEEP) {
            $changed[] = 'cover';
        }

        if ($changed === []) {
            if ($pending !== null) {
                PendingRevisions::close($pending, RevisionStatus::Superseded);
            }

            return null;
        }

        $revision->forceFill([
            'uuid' => $revision->uuid ?? (string) Str::uuid(),
            'article_id' => $article->id,
            'user_id' => $author->id,
            'status' => RevisionStatus::Pending,
            'version' => $pending !== null ? $pending->version + 1 : 1,
            'payload' => [...$content, 'cover' => $cover],
            'changed_fields' => $changed,
            'pending_key' => $article->id,
            'submitted_at' => now(),
        ])->save();

        /** @var array<string, mixed> $body */
        $body = $content['body'];
        ArticleImages::syncRevision($author, $revision, $article, $body);

        SecurityLog::record('blog.revision_submitted', [
            'user_id' => $author->id,
            'article' => $article->uuid,
            'revision' => $revision->uuid,
            'changed_fields' => $changed,
        ]);

        return $revision;
    }

    /**
     * Opération proposée sur la couverture, et rattachement du nouveau média à la révision.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function coverOperation(User $author, Article $article, ArticleRevision $revision, array $data): string
    {
        if (! array_key_exists('cover', $data)) {
            return $revision->exists ? $revision->coverOperation() : ArticleRevision::COVER_KEEP;
        }

        $uuid = is_string($data['cover']) ? $data['cover'] : null;
        $currentUuid = $article->cover_media_id !== null ? Media::query()->whereKey($article->cover_media_id)->value('uuid') : null;

        if ($uuid === null || $uuid === $currentUuid) {
            if ($revision->cover_media_id !== null) {
                $proposed = Media::query()->whereKey($revision->cover_media_id)->first();
                $revision->forceFill(['cover_media_id' => null]);

                if ($proposed !== null) {
                    $this->library->delete($proposed);
                }
            }

            return $uuid === null && $currentUuid !== null ? ArticleRevision::COVER_REMOVE : ArticleRevision::COVER_KEEP;
        }

        $revision->forceFill([
            'cover_media_id' => $this->library->replace($author, MediaPurpose::ArticleCover, $uuid, $revision->cover_media_id, 'cover'),
        ]);

        return ArticleRevision::COVER_REPLACE;
    }
}
