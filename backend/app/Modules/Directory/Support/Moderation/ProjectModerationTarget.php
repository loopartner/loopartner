<?php

namespace App\Modules\Directory\Support\Moderation;

use App\Models\User;
use App\Modules\Directory\Models\Project;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Projet publié signalable (module Moderation), désigné par son uuid. Seuls ceux qui voient
 * les projets peuvent les signaler : un prestataire avec un profil publié (ProjectPolicy::browse) ;
 * pour tout autre visiteur, le projet n'existe pas (404). Masqué, il disparaît de la
 * consultation et ses propositions en attente expirent (match-requests:expire).
 */
class ProjectModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return true;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        if ($viewer === null || ! $viewer->hasVerifiedEmail() || ! Gate::forUser($viewer)->allows('browse', Project::class)) {
            return null;
        }

        $project = Project::query()->browsable()->where('uuid', $ref)->first();

        return $project === null ? null : self::content($project);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $project = Project::query()->where('uuid', $ref)->first();

        return $project === null ? null : self::content($project);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $project = Project::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($project !== null) {
                Restriction::apply($project, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $project = Project::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($project !== null) {
                Restriction::lift($project, $decisionUuid);
            }
        });
    }

    private static function content(Project $project): TargetContent
    {
        return new TargetContent(
            type: 'project',
            ref: $project->uuid,
            ownerId: $project->user_id,
            label: $project->title,
            text: TargetContent::joinText([
                'Titre' => $project->title,
                'Description' => $project->description,
                'Ville' => $project->city,
                'Pays' => $project->country,
            ]),
            version: TargetContent::fingerprint([
                'title' => $project->title,
                'description' => $project->description,
                'city' => $project->city,
                'country' => $project->country,
                'budget' => [$project->budget_min, $project->budget_max, $project->currency],
            ]),
            restrictedBy: Restriction::decisionOf($project),
            publicPath: '/projects/'.$project->uuid,
        );
    }
}
