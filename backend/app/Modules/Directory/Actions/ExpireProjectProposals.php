<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;

/**
 * Fait expirer les propositions en attente sur un projet qui n'est plus visible (clôture ou
 * suppression). Appelée par CloseProject et DeleteProject ; les projets arrivés à expiration
 * sont traités par la tâche quotidienne ExpireMatchRequests.
 *
 * Les demandes de contact qui citent ce projet ne sont pas concernées : elles visent le
 * prestataire, pas le projet.
 */
class ExpireProjectProposals
{
    /**
     * @return int nombre de propositions expirées
     */
    public function handle(Project $project): int
    {
        $now = now();

        return MatchRequest::query()
            ->where('project_id', $project->id)
            ->where('type', MatchRequestType::Proposal->value)
            ->where('status', MatchRequestStatus::Pending->value)
            ->update(ExpireMatchRequests::expiredValues($now));
    }
}
