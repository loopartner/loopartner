<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Clôture un projet publié (expiré ou non). Un brouillon ou un projet déjà clos donne 409.
 * Les propositions en attente sur ce projet expirent dans la même transaction.
 */
class CloseProject
{
    public function __construct(private ExpireProjectProposals $expireProposals) {}

    /**
     * @throws ConflictHttpException
     */
    public function handle(Project $project): Project
    {
        return DB::transaction(function () use ($project): Project {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($project->status !== ProjectStatus::Published) {
                throw new ConflictHttpException('Seul un projet publié peut être clôturé.');
            }

            $project->forceFill([
                'status' => ProjectStatus::Closed,
                'closed_at' => now(),
            ])->save();

            $this->expireProposals->handle($project);

            return $project->load('category');
        });
    }
}
