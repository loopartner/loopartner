<?php

namespace App\Modules\Directory\Policies;

use App\Models\User;
use App\Modules\Accounts\Actions\GetEntitlements;
use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Directory\Exceptions\PremiumFeatureRequired;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Models\UserBlock;
use Illuminate\Auth\Access\Response;

/**
 * Projets : les particuliers et les entrepreneurs gèrent les leurs, les prestataires
 * consultent les projets publiés.
 *
 * Le projet d'un autre utilisateur est refusé en 404 (et non 403) : on ne révèle pas qu'il existe.
 * Les rôles sont lus avec l'enum public AccountType du module Accounts (pas un modèle).
 */
class ProjectPolicy
{
    private const NOT_FOUND = 'Projet introuvable.';

    /** Droit qui ouvre le détail des projets et les propositions (décision 81). */
    public const PROJECT_ACCESS = 'directory.project_access';

    public function __construct(
        private HasPublishedProviderProfile $hasPublishedProviderProfile,
        private GetEntitlements $entitlements,
    ) {}

    /**
     * Lister ses propres projets (GET /me/projects).
     */
    public function viewAnyOwn(User $user): bool
    {
        return $this->canOwnProjects($user);
    }

    /**
     * Créer un projet (POST /me/projects). La création ne demande pas d'email vérifié,
     * contrairement à la publication.
     */
    public function create(User $user): bool
    {
        return $this->canOwnProjects($user);
    }

    public function view(User $user, Project $project): Response
    {
        return $this->canOwnProjects($user) && $project->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound(self::NOT_FOUND);
    }

    public function update(User $user, Project $project): Response
    {
        return $this->view($user, $project);
    }

    public function delete(User $user, Project $project): Response
    {
        return $this->view($user, $project);
    }

    public function close(User $user, Project $project): Response
    {
        return $this->view($user, $project);
    }

    /**
     * Publier son projet : il faut en plus un email vérifié (403).
     */
    public function publish(User $user, Project $project): Response
    {
        $ownership = $this->view($user, $project);

        if ($ownership->denied()) {
            return $ownership;
        }

        return $user->hasVerifiedEmail()
            ? Response::allow()
            : Response::deny('Vous devez vérifier votre adresse email avant de publier un projet.');
    }

    /**
     * Consulter les projets publiés (GET /projects). L'email vérifié est exigé par le
     * middleware "verified" de la route. Il faut en plus un profil prestataire publié avec au
     * moins une catégorie : s'inscrire comme prestataire ne suffit pas pour collecter les projets.
     * Enfin, le droit directory.project_access (Premium, décision 81) : sans lui, le refus porte
     * le code premium_required, que les appelants transforment en 403 explicite
     * (Exceptions\PremiumFeatureRequired). Le prestataire garde la vitrine publique.
     */
    public function browse(User $user): Response
    {
        if (! $user->hasRole(AccountType::Prestataire->value)) {
            return Response::deny();
        }

        if (! $this->hasPublishedProviderProfile->handle($user)) {
            return Response::deny('Publiez votre profil prestataire, avec au moins une catégorie, pour consulter les projets.');
        }

        return $this->entitlements->handle($user)->allows(self::PROJECT_ACCESS)
            ? Response::allow()
            : Response::deny('Le détail des projets et les propositions sont réservés aux prestataires Premium.', PremiumFeatureRequired::CODE);
    }

    /**
     * Proposer ses services sur un projet publié (POST /projects/{uuid}/proposals) : les mêmes
     * conditions que pour consulter les projets, et aucun blocage entre le prestataire et le
     * porteur du projet. Un projet non visible donne 404 avant cette vérification.
     */
    public function propose(User $user, Project $project): Response
    {
        $browse = $this->browse($user);

        if ($browse->denied()) {
            return $browse;
        }

        return UserBlock::existsBetween($user->id, $project->user_id)
            ? Response::deny('Vous ne pouvez pas faire de proposition sur ce projet.')
            : Response::allow();
    }

    private function canOwnProjects(User $user): bool
    {
        return $user->hasAnyRole([AccountType::Particulier->value, AccountType::Entrepreneur->value]);
    }
}
