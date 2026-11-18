<?php

namespace App\Modules\Workspaces\Actions;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\ContentRules;
use App\Modules\Workspaces\Support\Plan;
use App\Modules\Workspaces\Support\Templates;
use App\Modules\Workspaces\Support\Workspaces;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Création, modification, archivage, activation et suppression d'un workspace (décision 63).
 *
 * - Workspaces actifs : au plus workspaces.active (gratuit 1, Premium 10 ; 403
 *   premium_required "multiple_workspaces" en gratuit, 409 au-delà du Premium), comptés sous le
 *   verrou de la ligne users ; au total, archivés compris : workspaces.max_workspaces (409).
 * - Modèle de projet (Premium) : étapes et tâches copiées à la création (Templates).
 * - Archivé : lecture seule, hors quota ; désarchiver recompte le quota.
 * - Activer : le workspace devient le plus récemment activé, donc modifiable si le plan en
 *   permet moins que le nombre de workspaces actifs (fin du Premium).
 * - Suppression : tout le contenu disparaît (cascade), même verrouillé ou archivé.
 */
class ManageWorkspace
{
    public function __construct(private Plan $plan, private Workspaces $workspaces, private Templates $templates) {}

    /**
     * @param  array<string, mixed>  $data  données validées
     */
    public function create(User $user, array $data): Workspace
    {
        $templateCode = isset($data['template']) && is_string($data['template']) ? $data['template'] : null;
        $template = $templateCode === null ? null : $this->templates->forCreation($user, $templateCode);
        unset($data['template']);

        return DB::transaction(function () use ($user, $data, $template, $templateCode): Workspace {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $this->assertTotalBelowLimit($user);
            $this->assertActiveBelowLimit($user);

            $workspace = new Workspace(self::normalize($data));
            ContentRules::ensureBudgetOrder($workspace->budget_min, $workspace->budget_max);
            $workspace->forceFill([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'status' => WorkspaceStatus::Active,
                'template_code' => $template === null ? null : $templateCode,
                'activated_at' => now(),
            ])->save();

            if ($template !== null) {
                $this->templates->apply($workspace, $template, (string) ($user->locale ?? 'fr'));
            }

            SecurityLog::record('workspaces.created', ['user_id' => $user->id, 'workspace' => $workspace->uuid, 'template' => $workspace->template_code]);

            return $workspace;
        });
    }

    /**
     * @param  array<string, mixed>  $data  données validées (modification partielle)
     */
    public function update(User $user, Workspace $workspace, array $data): Workspace
    {
        $this->workspaces->assertWritable($user, $workspace);

        $workspace->fill(self::normalize($data));
        ContentRules::ensureBudgetOrder($workspace->budget_min, $workspace->budget_max);
        $workspace->save();

        return $workspace;
    }

    public function archive(User $user, Workspace $workspace): Workspace
    {
        if ($workspace->isArchived()) {
            throw new ConflictHttpException('Ce workspace est déjà archivé.');
        }

        $workspace->forceFill(['status' => WorkspaceStatus::Archived, 'archived_at' => now()])->save();

        return $workspace;
    }

    public function unarchive(User $user, Workspace $workspace): Workspace
    {
        if (! $workspace->isArchived()) {
            throw new ConflictHttpException('Ce workspace n\'est pas archivé.');
        }

        return DB::transaction(function () use ($user, $workspace): Workspace {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $this->assertActiveBelowLimit($user);

            $workspace->forceFill(['status' => WorkspaceStatus::Active, 'archived_at' => null, 'activated_at' => now()])->save();

            return $workspace;
        });
    }

    public function activate(User $user, Workspace $workspace): Workspace
    {
        if ($workspace->isArchived()) {
            throw new ConflictHttpException('Ce workspace est archivé : désarchivez-le d\'abord.');
        }

        $workspace->forceFill(['activated_at' => now()])->save();

        return $workspace;
    }

    public function delete(User $user, Workspace $workspace): void
    {
        $workspace->delete();

        SecurityLog::record('workspaces.deleted', ['user_id' => $user->id, 'workspace' => $workspace->uuid]);
    }

    private function assertTotalBelowLimit(User $user): void
    {
        $max = (int) config('workspaces.max_workspaces');

        if (Workspace::query()->where('user_id', $user->id)->count() >= $max) {
            throw new ConflictHttpException(sprintf('Vous avez déjà %d workspaces : supprimez-en un avant d\'en créer un autre.', $max));
        }
    }

    private function assertActiveBelowLimit(User $user): void
    {
        $active = Workspace::query()->where('user_id', $user->id)->where('status', WorkspaceStatus::Active->value)->count();

        $this->plan->assertBelow($user, 'workspaces.active', $active, 'multiple_workspaces', 'Vous avez déjà %d workspaces actifs : archivez-en un avant d\'en activer un autre.');
    }

    /**
     * Codes pays et devise en majuscules.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        foreach (['country', 'currency'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = strtoupper($data[$field]);
            }
        }

        return $data;
    }
}
