<?php

namespace App\Modules\Moderation\Listeners;

use App\Modules\Accounts\Events\AccountDeleting;
use App\Modules\Moderation\Actions\ForgetUserModeration;

/**
 * Suppression d'un compte (événement du module Accounts) : signalements, décisions et recours
 * traités par ForgetUserModeration. Synchrone (pas de ShouldQueue) : il s'exécute dans la
 * transaction de DeleteAccount, et une erreur annule toute la suppression.
 */
class ForgetUserModerationOnAccountDeletion
{
    public function __construct(private ForgetUserModeration $forgetUserModeration) {}

    public function handle(AccountDeleting $event): void
    {
        $this->forgetUserModeration->handle($event->user);
    }
}
