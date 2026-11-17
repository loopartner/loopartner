<?php

namespace App\Modules\Marketplace\Listeners;

use App\Modules\Accounts\Events\AccountDeleting;
use App\Modules\Marketplace\Actions\ForgetUserMarketplace;

/**
 * Suppression d'un compte (événement du module Accounts) : offres, identité professionnelle et
 * devis traités par ForgetUserMarketplace. Synchrone (pas de ShouldQueue) : il s'exécute dans la
 * transaction de DeleteAccount, et une erreur annule toute la suppression.
 */
class ForgetUserMarketplaceOnAccountDeletion
{
    public function __construct(private ForgetUserMarketplace $forgetUserMarketplace) {}

    public function handle(AccountDeleting $event): void
    {
        $this->forgetUserMarketplace->handle($event->user);
    }
}
