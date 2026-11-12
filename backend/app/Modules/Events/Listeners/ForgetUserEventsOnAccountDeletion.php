<?php

namespace App\Modules\Events\Listeners;

use App\Modules\Accounts\Events\AccountDeleting;
use App\Modules\Events\Actions\ForgetUserEvents;

/**
 * Suppression d'un compte (événement du module Accounts) : inscriptions et événements traités
 * par ForgetUserEvents. Synchrone (pas de ShouldQueue) : il s'exécute dans la transaction de
 * DeleteAccount, et une erreur annule toute la suppression.
 */
class ForgetUserEventsOnAccountDeletion
{
    public function __construct(private ForgetUserEvents $forgetUserEvents) {}

    public function handle(AccountDeleting $event): void
    {
        $this->forgetUserEvents->handle($event->user);
    }
}
