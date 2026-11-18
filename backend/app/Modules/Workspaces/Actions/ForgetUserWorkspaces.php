<?php

namespace App\Modules\Workspaces\Actions;

use App\Models\User;
use App\Modules\Workspaces\Models\CopilotEntry;
use App\Modules\Workspaces\Models\Workspace;

/**
 * Suppression d'un compte (événement AccountDeleting) : tous les workspaces de l'utilisateur et
 * leur contenu (objectifs, étapes, tâches, cahiers des charges, suggestions du copilote) et le
 * décompte de ses générations sont supprimés. Rien d'un workspace n'est jamais visible d'autrui :
 * rien n'est conservé pour une autre personne. Les projets Directory reliés suivent les règles
 * de Directory.
 */
class ForgetUserWorkspaces
{
    public function handle(User $user): void
    {
        Workspace::query()->where('user_id', $user->id)->delete();
        CopilotEntry::query()->where('user_id', $user->id)->delete();
    }
}
