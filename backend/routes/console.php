<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Planificateur
|--------------------------------------------------------------------------
|
| En production (Hostinger mutualisé), une tâche cron lance chaque minute :
|   php artisan schedule:run
| Aucun processus permanent : chaque tâche doit se terminer d'elle-même.
|
*/

// Médias jamais rattachés depuis plus de 24 heures : supprimés avec leurs fichiers.
Schedule::command('media:prune-orphans')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Projets (module Directory) publiés depuis plus de 60 jours : clôturés.
Schedule::command('projects:close-expired')
    ->dailyAt('02:00')
    ->withoutOverlapping();

// Demandes de mise en relation (module Directory) en attente depuis 30 jours, ou propositions sur
// un projet qui n'est plus publié : expirées. Après la clôture des projets expirés.
Schedule::command('match-requests:expire')
    ->dailyAt('02:30')
    ->withoutOverlapping();

// Minimisation des données de la mise en relation (durées dans config/security.php, retention).
Schedule::command('matching:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Événements (module Events) : rappel aux inscrits 24 heures avant le début, sans doublon.
Schedule::command('events:send-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

// Événements terminés : statut "past" ; soumissions dont la date est passée : refusées.
Schedule::command('events:close-past')
    ->hourly()
    ->withoutOverlapping(30);

// Minimisation des données des événements (durées dans config/security.php, retention).
Schedule::command('events:prune')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Événements (module Events) : récapitulatif des éléments en attente de validation envoyé aux
// admins, au plus une fois toutes les 30 minutes (config/events.php, moderation_digest_minutes).
Schedule::command('events:notify-moderators')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Minimisation des données du Blog (durées dans config/security.php, retention).
Schedule::command('blog:prune')
    ->dailyAt('05:00')
    ->withoutOverlapping();

// Blog : récapitulatif des articles et modifications en attente de validation envoyé aux
// rédacteurs et admins, au plus une fois toutes les 30 minutes (config/blog.php).
Schedule::command('blog:notify-moderators')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Journal d'audit des décisions d'administration (noyau) : entrées au-delà de leur durée de
// conservation (config/security.php, retention.admin_audit_months) supprimées.
Schedule::command('admin-audit:prune')
    ->dailyAt('04:30')
    ->withoutOverlapping();

// File d'attente (driver database) : traitée chaque minute, sans worker permanent.
// Le worker s'arrête dès que la file est vide, et au plus tard après 50 secondes pour ne pas
// déborder sur le schedule:run suivant. Le verrou anti-chevauchement expire après 5 minutes
// (et non 24 heures par défaut) si un processus est tué sans le libérer.
// Déclarée en dernier : les tâches précédentes passent avant le worker dans chaque schedule:run.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(5);
