<?php

namespace App\Modules\Workspaces\Policies;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use Illuminate\Auth\Access\Response;

/**
 * Workspaces et copilote : réservés aux particuliers et aux entrepreneurs (décision 63 ; 403
 * pour les autres rôles, équipe comprise). L'email vérifié n'est pas exigé pour le workspace,
 * seulement pour une génération du copilote (décision 61). Le workspace d'un autre utilisateur
 * donne 404 : la recherche est limitée aux workspaces de l'utilisateur connecté
 * (Support/Workspaces::own).
 */
class WorkspacePolicy
{
    public function use(User $user): Response
    {
        return $user->hasAnyRole([AccountType::Particulier->value, AccountType::Entrepreneur->value])
            ? Response::allow()
            : Response::deny('Les workspaces sont réservés aux particuliers et aux entrepreneurs.');
    }
}
