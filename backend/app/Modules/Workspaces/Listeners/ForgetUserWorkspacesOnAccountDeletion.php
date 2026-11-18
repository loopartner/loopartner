<?php

namespace App\Modules\Workspaces\Listeners;

use App\Modules\Accounts\Events\AccountDeleting;
use App\Modules\Workspaces\Actions\ForgetUserWorkspaces;

/**
 * Suppression d'un compte (événement du module Accounts) : workspaces supprimés par
 * ForgetUserWorkspaces. Synchrone (pas de ShouldQueue) : il s'exécute dans la transaction de
 * DeleteAccount, et une erreur annule toute la suppression.
 */
class ForgetUserWorkspacesOnAccountDeletion
{
    public function __construct(private ForgetUserWorkspaces $forgetUserWorkspaces) {}

    public function handle(AccountDeleting $event): void
    {
        $this->forgetUserWorkspaces->handle($event->user);
    }
}
