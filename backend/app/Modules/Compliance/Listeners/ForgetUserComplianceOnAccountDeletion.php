<?php

namespace App\Modules\Compliance\Listeners;

use App\Modules\Accounts\Events\AccountDeleting;
use App\Modules\Compliance\Actions\ForgetUserCompliance;

/**
 * Suppression d'un compte (événement du module Accounts) : données du suivi administratif
 * supprimées par ForgetUserCompliance. Synchrone (pas de ShouldQueue) : il s'exécute dans la
 * transaction de DeleteAccount, et une erreur annule toute la suppression.
 */
class ForgetUserComplianceOnAccountDeletion
{
    public function __construct(private ForgetUserCompliance $forgetUserCompliance) {}

    public function handle(AccountDeleting $event): void
    {
        $this->forgetUserCompliance->handle($event->user);
    }
}
