<?php

namespace App\Modules\Blog\Support;

use App\Models\Media;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Support\Media\MediaLibrary;

/**
 * Révisions qui ne seront pas appliquées (refusées, retirées, remplacées, ou devenues sans
 * objet) : leurs images et leur couverture proposées sont supprimées. À appeler dans une
 * transaction.
 */
final class PendingRevisions
{
    /**
     * Révision en attente de l'article, devenue sans objet (dépublication, suppression…).
     */
    public static function closeFor(Article $article): void
    {
        ArticleRevision::query()
            ->where('article_id', $article->id)
            ->where('status', RevisionStatus::Pending->value)
            ->get()
            ->each(fn (ArticleRevision $revision) => self::close($revision, RevisionStatus::Superseded));
    }

    /**
     * Clôt une révision en attente avec le statut donné, sans l'appliquer.
     */
    public static function close(ArticleRevision $revision, RevisionStatus $status, ?int $decidedBy = null, ?string $reason = null): void
    {
        self::discardProposals($revision);

        $revision->forceFill([
            'status' => $status,
            'pending_key' => null,
            'decided_at' => now(),
            'decided_by' => $decidedBy,
            'rejection_reason' => $reason,
        ])->save();
    }

    /**
     * Supprime les images et la couverture proposées par une révision qui ne sera pas appliquée.
     */
    public static function discardProposals(ArticleRevision $revision): void
    {
        ArticleImages::discardRevision($revision);

        if ($revision->cover_media_id === null) {
            return;
        }

        $media = Media::query()->whereKey($revision->cover_media_id)->first();
        $revision->forceFill(['cover_media_id' => null]);

        if ($media !== null) {
            app(MediaLibrary::class)->delete($media);
        }
    }
}
