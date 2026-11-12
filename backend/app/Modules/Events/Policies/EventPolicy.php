<?php

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use App\Modules\Directory\Actions\IsBlockedBetween;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\Organizers;
use Illuminate\Auth\Access\Response;

/**
 * Événements : gestion par leur organisateur (/me/events) et inscription des participants.
 *
 * - Seul l'organisateur voit, modifie, annule son événement et en voit les inscrits : tout autre
 *   utilisateur reçoit 404 (et non 403), admin compris. La modération passe par ses propres
 *   endpoints (/admin/…, middleware EnsureModerator), jamais par ceux-ci.
 * - Peuvent organiser : les prestataires avec un profil publié (et au moins une catégorie), les
 *   entrepreneurs, les rédacteurs et les admins. Les rôles sont lus avec l'enum public
 *   AccountType et l'Action publique HasPublishedProviderProfile du module Accounts.
 */
class EventPolicy
{
    private const NOT_FOUND = 'Événement introuvable.';

    public function __construct(
        private HasPublishedProviderProfile $hasPublishedProviderProfile,
        private IsBlockedBetween $isBlockedBetween,
    ) {}

    /**
     * Lister ses propres événements (GET /me/events).
     */
    public function viewAnyOwn(User $user): bool
    {
        return Organizers::hasOrganizerRole($user);
    }

    /**
     * Créer un événement (POST /me/events), toujours en brouillon.
     */
    public function create(User $user): Response
    {
        if (! Organizers::hasOrganizerRole($user)) {
            return Response::deny();
        }

        if (Organizers::isProvider($user) && ! $this->hasPublishedProviderProfile->handle($user)) {
            return Response::deny('Publiez votre profil prestataire, avec au moins une catégorie, pour proposer un événement.');
        }

        return Response::allow();
    }

    public function view(User $user, Event $event): Response
    {
        return Organizers::hasOrganizerRole($user) && $event->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound(self::NOT_FOUND);
    }

    public function update(User $user, Event $event): Response
    {
        return $this->view($user, $event);
    }

    public function delete(User $user, Event $event): Response
    {
        return $this->view($user, $event);
    }

    public function cancel(User $user, Event $event): Response
    {
        return $this->view($user, $event);
    }

    /**
     * Voir et retirer les inscrits, publier une annonce.
     */
    public function manageAttendees(User $user, Event $event): Response
    {
        return $this->view($user, $event);
    }

    /**
     * Soumettre à la validation (ou publier directement, pour un admin) : il faut en plus
     * toujours pouvoir organiser (profil prestataire encore publié…).
     */
    public function submit(User $user, Event $event): Response
    {
        $ownership = $this->view($user, $event);

        return $ownership->denied() ? $ownership : $this->create($user);
    }

    /**
     * S'inscrire : jamais à son propre événement, ni à celui d'un organisateur avec lequel
     * existe un blocage (dans un sens ou dans l'autre, module Directory). L'événement non
     * visible donne 404 avant cette vérification ; complet, payant ou commencé : 409 (Action).
     */
    public function register(User $user, Event $event): Response
    {
        if ($event->user_id === $user->id) {
            return Response::deny('Vous ne pouvez pas vous inscrire à votre propre événement.');
        }

        if ($event->user_id !== null && $this->isBlockedBetween->handle($user->id, $event->user_id)) {
            return Response::deny('Vous ne pouvez pas vous inscrire à cet événement.');
        }

        return Response::allow();
    }
}
