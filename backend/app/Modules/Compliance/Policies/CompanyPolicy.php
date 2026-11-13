<?php

namespace App\Modules\Compliance\Policies;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Compliance\Models\Company;
use Illuminate\Auth\Access\Response;

/**
 * Suivi administratif : réservé aux prestataires et aux entrepreneurs (l'email vérifié est
 * exigé par le middleware "verified" des routes). Toutes les données d'une entreprise
 * (échéances, tâches, chiffre d'affaires…) suivent cette Policy.
 *
 * L'entreprise d'un autre utilisateur donne 404, quel que soit le rôle, admins compris : les
 * admins ne gèrent que les règles, jamais les données des entreprises.
 */
class CompanyPolicy
{
    private const NOT_FOUND = 'Entreprise introuvable.';

    /**
     * Fonctions du module (liste, création, réglages, abonnement calendrier, export).
     */
    public function use(User $user): Response
    {
        return self::hasRole($user)
            ? Response::allow()
            : Response::deny('Le suivi administratif est réservé aux prestataires et aux entrepreneurs.');
    }

    public function viewAny(User $user): Response
    {
        return $this->use($user);
    }

    public function create(User $user): Response
    {
        return $this->use($user);
    }

    public function view(User $user, Company $company): Response
    {
        return self::hasRole($user) && $company->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound(self::NOT_FOUND);
    }

    public function update(User $user, Company $company): Response
    {
        return $this->view($user, $company);
    }

    public function delete(User $user, Company $company): Response
    {
        return $this->view($user, $company);
    }

    private static function hasRole(User $user): bool
    {
        return $user->hasAnyRole([AccountType::Prestataire->value, AccountType::Entrepreneur->value]);
    }
}
