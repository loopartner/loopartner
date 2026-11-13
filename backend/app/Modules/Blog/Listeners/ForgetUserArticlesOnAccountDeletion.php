<?php

namespace App\Modules\Blog\Listeners;

use App\Modules\Accounts\Events\AccountDeleting;
use App\Modules\Blog\Actions\ForgetUserArticles;

/**
 * Suppression d'un compte (événement du module Accounts) : articles traités par
 * ForgetUserArticles. Synchrone (pas de ShouldQueue) : il s'exécute dans la transaction de
 * DeleteAccount, et une erreur annule toute la suppression.
 */
class ForgetUserArticlesOnAccountDeletion
{
    public function __construct(private ForgetUserArticles $forgetUserArticles) {}

    public function handle(AccountDeleting $event): void
    {
        $this->forgetUserArticles->handle($event->user);
    }
}
