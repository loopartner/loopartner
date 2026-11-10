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
