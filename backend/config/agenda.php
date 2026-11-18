<?php

use App\Modules\Appointments\Support\AppointmentsAgendaSource;
use App\Modules\Compliance\Support\ComplianceAgendaSource;
use App\Modules\Events\Support\EventsAgendaSource;
use App\Modules\Workspaces\Support\WorkspacesAgendaSource;

/*
|--------------------------------------------------------------------------
| Agenda unifié (noyau, lot 4)
|--------------------------------------------------------------------------
|
| Sources de l'agenda : clé stable => adaptateur du module qui possède les éléments
| (App\Support\Agenda\AgendaSource). L'abonnement unique (.ics) ne reprend que les titres et
| les dates. Limites de débit : config/security.php (agenda, agenda_feed, agenda_feed_manage).
*/

return [

    'sources' => [
        'appointments' => AppointmentsAgendaSource::class,
        'events' => EventsAgendaSource::class,
        'compliance' => ComplianceAgendaSource::class,
        'workspaces' => WorkspacesAgendaSource::class,
    ],

    // GET /me/agenda : de from à to inclus (journées UTC), 30 jours par défaut, 92 au plus.
    'default_range_days' => 30,
    'max_range_days' => 92,

    // Abonnement : du mois écoulé aux douze prochains mois.
    'feed_past_days' => 31,
    'feed_future_months' => 12,

];
