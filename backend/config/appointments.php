<?php

/*
|--------------------------------------------------------------------------
| Module Appointments (rendez-vous)
|--------------------------------------------------------------------------
|
| Réglages métier du lot 4 (décisions 52 à 58, docs/v1/DECISIONS.md). Les quotas gratuits et
| Premium des prestataires sont lus par config/entitlements.php (références ['config' => …]).
| Limites de débit et durées de conservation : config/security.php. Textes destinés aux
| utilisateurs et points à faire valider : docs/v1/appointments-rules.md.
*/

return [

    // Fuseau proposé par défaut au prestataire (plages saisies en heure locale).
    'default_timezone' => 'Europe/Paris',

    /*
    | Quotas des prestataires (décision 54), par plan. Délais de rappel en minutes avant le
    | début du rendez-vous (durée réelle) : 120 = 2 h, 1440 = 24 h, 2880 = 48 h.
    */
    'plans' => [
        'free' => [
            'active_types' => 2,
            'weekly_ranges' => 10,
            'upcoming_exceptions' => 20,
            'horizon_days' => 30,
            'upcoming_appointments' => 30,
            'reminder_offsets' => [1440],
            'max_reminder_offsets' => 1,
        ],
        'premium' => [
            'active_types' => 10,
            'weekly_ranges' => 40,
            'upcoming_exceptions' => 100,
            'horizon_days' => 90,
            'upcoming_appointments' => 200,
            'reminder_offsets' => [120, 1440, 2880],
            'max_reminder_offsets' => 2,
        ],
    ],

    // Rappels appliqués quand le réglage du prestataire n'est plus permis par son plan.
    'default_reminder_offsets' => [1440],

    /*
    | Réglages du calendrier : valeurs par défaut et bornes.
    */
    'defaults' => [
        'slot_step_minutes' => 30,
        'buffer_minutes' => 0,
        'min_notice_hours' => 12,
        'horizon_days' => 30,
    ],
    'slot_steps' => [15, 30, 60],
    'max_buffer_minutes' => 120,
    'max_min_notice_hours' => 168,

    // Types de rendez-vous : durée en minutes, et nombre total (actifs ou non) par prestataire.
    'durations' => ['min' => 15, 'max' => 480, 'step' => 5],
    'max_types' => 30,

    // Exceptions : au plus un an à l'avance.
    'exceptions_max_days_ahead' => 366,

    // Créneaux demandés en une fois (aperçu, réservation) : 31 jours au plus.
    'max_slot_range_days' => 31,

    /*
    | Demandes et propositions en attente : expirées après ce délai, ou plus tôt au délai de
    | prévenance avant le début (décision 55).
    */
    'request_expiration_hours' => 48,

    // Reports par rendez-vous (décision 55).
    'max_reschedules' => 3,

    // Note libre (décision 58) ; motif d'une réservation de la page publique : 20 caractères
    // au moins (il devient le message de la demande de contact).
    'note_max_length' => 500,
    'public_note_min_length' => 20,

    // Clients (tous rôles, décision 54).
    'client' => [
        'max_pending_requests' => 5,
        'max_upcoming' => 20,
        'max_upcoming_per_relation' => 3,
    ],

    // Prestataires : propositions de créneau en attente (précision du 2026-11-17).
    'provider' => [
        'max_pending_proposals_per_relation' => 3,
        'max_pending_proposals' => 30,
    ],

];
