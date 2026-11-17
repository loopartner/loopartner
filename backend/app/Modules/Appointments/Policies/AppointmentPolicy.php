<?php

namespace App\Modules\Appointments\Policies;

use App\Models\User;
use App\Modules\Appointments\Models\Appointment;
use Illuminate\Auth\Access\Response;

/**
 * Rendez-vous : seules ses deux parties le voient et agissent dessus. Tout autre utilisateur
 * reçoit 404, admins compris (la modération passe par /admin/moderation). Une partie qui
 * tente l'action réservée à l'autre reçoit 403 (vérifié par chaque Action).
 */
class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): Response
    {
        return $appointment->partyOf($user) !== null
            ? Response::allow()
            : Response::denyAsNotFound('Rendez-vous introuvable.');
    }
}
