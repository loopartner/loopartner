<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;

/**
 * Clôture les projets publiés dont la date d'expiration est passée.
 *
 * Une seule requête UPDATE : rapide même avec beaucoup de projets, et sans risque si la tâche
 * est relancée. Les projets expirés sont déjà invisibles des prestataires (scope live()) :
 * cette clôture rend leur statut cohérent pour leur propriétaire.
 */
class CloseExpiredProjects
{
    /**
     * @return int nombre de projets clôturés
     */
    public function handle(): int
    {
        $now = now();

        return Project::query()
            ->where('status', ProjectStatus::Published->value)
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => ProjectStatus::Closed->value,
                'closed_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
