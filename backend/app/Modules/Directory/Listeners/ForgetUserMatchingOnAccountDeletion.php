<?php

namespace App\Modules\Directory\Listeners;

use App\Modules\Accounts\Events\AccountDeleting;
use App\Modules\Directory\Actions\ForgetUserMatching;

/**
 * Suppression d'un compte (événement du module Accounts) : demandes et conversations traitées
 * par ForgetUserMatching. Synchrone (pas de ShouldQueue) : il s'exécute dans la transaction de
 * DeleteAccount, et une erreur annule toute la suppression.
 */
class ForgetUserMatchingOnAccountDeletion
{
    public function __construct(private ForgetUserMatching $forgetUserMatching) {}

    public function handle(AccountDeleting $event): void
    {
        $this->forgetUserMatching->handle($event->user);
    }
}
