<?php

namespace App\Modules\Blog\Actions;

use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Support\PendingRevisions;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Retire la modification en attente de validation d'un article publié (404 s'il n'y en a
 * pas). La version publiée reste affichée ; les images et la couverture proposées sont
 * supprimées.
 */
class WithdrawRevision
{
    public function handle(Article $article): void
    {
        DB::transaction(function () use ($article): void {
            $revision = ArticleRevision::query()
                ->where('article_id', $article->id)
                ->where('status', RevisionStatus::Pending->value)
                ->lockForUpdate()
                ->first();

            abort_if($revision === null, 404, 'Aucune modification en attente pour cet article.');

            PendingRevisions::close($revision, RevisionStatus::Withdrawn);

            SecurityLog::record('blog.revision_withdrawn', ['article' => $article->uuid, 'revision' => $revision->uuid]);
        });
    }
}
