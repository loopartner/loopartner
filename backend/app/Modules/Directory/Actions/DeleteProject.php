<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Supprime définitivement un projet, quel que soit son statut.
 *
 * Point d'entrée unique : les propositions en attente sur ce projet expirent d'abord. Les
 * demandes qui le citent (propositions traitées, contacts) gardent leur historique avec
 * project_id à null (clé étrangère "null on delete").
 */
class DeleteProject
{
    public function __construct(private ExpireProjectProposals $expireProposals) {}

    public function handle(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $this->expireProposals->handle($project);

            $project->delete();
        });
    }
}
