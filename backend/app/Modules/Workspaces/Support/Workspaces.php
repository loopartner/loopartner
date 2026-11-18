<?php

namespace App\Modules\Workspaces\Support;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accès aux workspaces de l'utilisateur connecté.
 *
 * - own() : le workspace de l'utilisateur, sinon 404 (celui d'un autre utilisateur n'existe pas
 *   pour lui, admins compris).
 * - Verrouillage (fin du Premium, décision 63) : quand le plan permet moins de workspaces
 *   actifs que l'utilisateur n'en a, seuls les plus récemment activés (activated_at) restent
 *   modifiables ; les autres se lisent, s'archivent et se suppriment (403 premium_required
 *   "multiple_workspaces" sinon). Rien n'est supprimé.
 * - Un workspace archivé est en lecture seule (409) jusqu'à son désarchivage.
 */
final class Workspaces
{
    public function __construct(private Plan $plan) {}

    public function own(User $user, string $uuid): Workspace
    {
        $workspace = Workspace::query()->where('uuid', $uuid)->where('user_id', $user->id)->first();

        if ($workspace === null) {
            throw new NotFoundHttpException('Workspace introuvable.');
        }

        return $workspace;
    }

    /**
     * Verrouille la ligne du workspace (opérations qui comptent ou ordonnent son contenu) et
     * la relit.
     */
    public static function lock(Workspace $workspace): Workspace
    {
        return Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Identifiants des workspaces actifs modifiables de l'utilisateur.
     *
     * @return list<int>
     */
    public function writableIds(int $userId): array
    {
        $limit = $this->plan->limit($userId, 'workspaces.active');
        $query = Workspace::query()
            ->where('user_id', $userId)
            ->where('status', WorkspaceStatus::Active->value)
            ->orderByDesc('activated_at')
            ->orderByDesc('id');

        if ($limit !== null) {
            $query->limit(max(0, $limit));
        }

        return $query->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function isLocked(Workspace $workspace): bool
    {
        return ! $workspace->isArchived() && ! in_array($workspace->id, $this->writableIds($workspace->user_id), true);
    }

    /**
     * Le contenu du workspace peut-il être modifié ? Archivé : 409 ; verrouillé : 403.
     */
    public function assertWritable(User $user, Workspace $workspace): void
    {
        if ($workspace->isArchived()) {
            throw new ConflictHttpException('Ce workspace est archivé : désarchivez-le pour le modifier.');
        }

        if ($this->isLocked($workspace)) {
            $this->plan->requirePremium($user, 'multiple_workspaces');
        }
    }
}
