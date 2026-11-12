<?php

/*
|--------------------------------------------------------------------------
| Module Events (agenda d'événements)
|--------------------------------------------------------------------------
|
| Réglages métier du module. Les limites de débit et les durées de conservation sont dans
| config/security.php (rate_limits.event_*, retention.event_*).
|
*/

return [

    /*
    | Nombre maximal d'inscrits à un événement, même sans capacité indiquée par l'organisateur
    | (la capacité ne peut pas dépasser cette valeur).
    |
    | Lien avec le quota d'emails : les emails partent par Resend, dont l'offre de départ a un
    | quota quotidien et mensuel limité. Chaque inscrit reçoit au minimum DEUX emails (la
    | confirmation d'inscription et le rappel 24 heures avant), et jusqu'à quatre ou plus en cas de
    | modification importante, d'annonce ou d'annulation. Un événement complet de 300 inscrits
    | représente donc au moins 600 emails, dont 300 rappels envoyés le même jour. Avant
    | d'augmenter cette valeur, vérifier le quota de l'offre Resend (voir SECURITY.md).
    */
    'max_registrations' => 300,

    // Événements en attente de validation (pending_review) en même temps, par organisateur.
    'max_pending_review_per_organizer' => 3,

    // Événements publiés et pas encore terminés en même temps, par organisateur.
    'max_upcoming_published_per_organizer' => 20,

    // Durée maximale d'un événement, en jours (salons sur plusieurs jours).
    'max_duration_days' => 31,

    // Date de début au plus tard dans ce nombre de mois.
    'max_months_ahead' => 24,

    // Délai minimal, en minutes, entre la soumission (ou une révision) et le début de l'événement.
    'min_lead_minutes' => 60,

    // Catégories de services par événement.
    'max_categories' => 3,

    // Annonces aux inscrits : au plus "per_event" par événement, et une seule par
    // "cooldown_hours" heures pour un même événement.
    'announcements' => [
        'per_event' => 5,
        'cooldown_hours' => 24,
    ],

    // Rappel envoyé aux inscrits ce nombre d'heures avant le début (events:send-reminders).
    'reminder_hours_before' => 24,

    // Les admins reçoivent au plus un email de récapitulatif de modération par période
    // (events:notify-moderators), en minutes.
    'moderation_digest_minutes' => 30,

];
