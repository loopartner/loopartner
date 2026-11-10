<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Models\Project;

/**
 * Supprime définitivement un projet, quel que soit son statut.
 *
 * Point d'entrée unique : la mise en relation (tâche suivante) pourra y ajouter le
 * traitement des demandes liées au projet.
 */
class DeleteProject
{
    public function handle(Project $project): void
    {
        $project->delete();
    }
}
