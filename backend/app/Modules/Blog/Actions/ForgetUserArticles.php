<?php

namespace App\Modules\Blog\Actions;

use App\Models\User;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Support\PendingRevisions;
use Illuminate\Support\Collection;

/**
 * Suppression d'un compte (événement AccountDeleting du module Accounts), dans la transaction
 * de DeleteAccount :
 *
 * - articles d'un prestataire ou d'un entrepreneur : SUPPRIMÉS, publiés compris (DeleteArticle :
 *   images, couvertures, révisions, slug mis en retraite). Un article signé est une donnée
 *   personnelle et la suppression du compte vaut demande d'effacement (RGPD art. 17) ; les
 *   conserver exigerait une cession de droits prévue par les CGU.
 * - articles de l'équipe (signés « la rédaction », écrits pour la plateforme) : DÉTACHÉS du
 *   compte (user_id à null) et conservés. Leurs images appartenaient au compte : la brique
 *   média les supprime ensuite (deleteOwnedBy), et le corps présenté omet les images disparues.
 *
 * Les décomptes du quota gratuit disparaissent avec le compte (clé étrangère en cascade).
 */
class ForgetUserArticles
{
    public function __construct(private DeleteArticle $deleteArticle) {}

    public function handle(User $user): void
    {
        Article::query()
            ->where('user_id', $user->id)
            ->chunkById(100, function (Collection $articles): void {
                foreach ($articles as $article) {
                    /** @var Article $article */
                    if ($article->author_kind->isEditorial()) {
                        PendingRevisions::closeFor($article);
                        $article->forceFill(['user_id' => null])->save();

                        continue;
                    }

                    $this->deleteArticle->handle($article);
                }
            });
    }
}
