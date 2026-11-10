<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Publie un projet pour Project::PUBLICATION_DAYS jours.
 *
 * - Possible depuis un brouillon, un projet clos ou un projet expiré (republication).
 * - Un projet déjà publié et non expiré donne 409.
 * - Au plus Project::MAX_PUBLISHED_PER_USER projets publiés et non expirés par utilisateur
 *   (409 au-delà). La ligne de l'utilisateur est verrouillée pendant le comptage : deux
 *   publications simultanées ne peuvent pas dépasser le plafond.
 *
 * L'email vérifié est exigé par ProjectPolicy::publish (403).
 */
class PublishProject
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(User $user, Project $project): Project
    {
        return DB::transaction(function () use ($user, $project): Project {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($project->isLive()) {
                throw new ConflictHttpException('Ce projet est déjà publié.');
            }

            $published = Project::query()
                ->where('user_id', $user->id)
                ->live()
                ->count();

            if ($published >= Project::MAX_PUBLISHED_PER_USER) {
                throw new ConflictHttpException(sprintf(
                    'Vous avez déjà %d projets publiés : clôturez-en un avant d\'en publier un autre.',
                    Project::MAX_PUBLISHED_PER_USER,
                ));
            }

            $now = now();

            $project->forceFill([
                'status' => ProjectStatus::Published,
                'published_at' => $now,
                'expires_at' => $now->copy()->addDays(Project::PUBLICATION_DAYS),
                'closed_at' => null,
            ])->save();

            return $project->load('category');
        });
    }
}
