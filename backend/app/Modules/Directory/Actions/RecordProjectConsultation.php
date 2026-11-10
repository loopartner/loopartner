<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Journalise une consultation des projets publiés (liste ou détail) dans le canal "security",
 * pour repérer une collecte massive : identifiant du prestataire, IP, route, page, filtres ou
 * uuid du projet. Jamais d'email ni de nom.
 *
 * Un compteur quotidien (cache, jour UTC) déclenche un avertissement unique
 * "projects.scraping_suspected" quand le prestataire atteint
 * security.scraping.projects_daily_threshold consultations dans la journée.
 */
class RecordProjectConsultation
{
    /**
     * @param  array<string, mixed>  $context  page et filtres (liste) ou uuid du projet (détail)
     */
    public function handle(Request $request, User $user, string $event, array $context): void
    {
        SecurityLog::record($event, ['user_id' => $user->id, ...$context], request: $request);

        $threshold = (int) config('security.scraping.projects_daily_threshold');
        $key = sprintf('security:project-consultations:%d:%s', $user->id, now()->format('Y-m-d'));

        Cache::add($key, 0, now()->endOfDay()->addHour());
        $count = (int) Cache::increment($key);

        if ($threshold > 0 && $count === $threshold) {
            SecurityLog::record('projects.scraping_suspected', [
                'user_id' => $user->id,
                'consultations_today' => $count,
                'threshold' => $threshold,
            ], 'warning', $request);
        }
    }
}
