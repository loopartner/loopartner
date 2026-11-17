<?php

namespace App\Modules\Appointments\Listeners;

use App\Modules\Accounts\Events\AccountDeleting;
use App\Modules\Appointments\Actions\ForgetUserAppointments;

/**
 * Suppression d'un compte (événement du module Accounts) : rendez-vous traités par
 * ForgetUserAppointments. Synchrone (pas de ShouldQueue) : il s'exécute dans la transaction de
 * DeleteAccount, et une erreur annule toute la suppression.
 */
class ForgetUserAppointmentsOnAccountDeletion
{
    public function __construct(private ForgetUserAppointments $forgetUserAppointments) {}

    public function handle(AccountDeleting $event): void
    {
        $this->forgetUserAppointments->handle($event->user);
    }
}
