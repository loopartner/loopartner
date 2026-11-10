<?php

namespace App\Modules\Directory\Policies;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\UserBlock;
use Illuminate\Auth\Access\Response;

/**
 * Demandes de mise en relation.
 *
 * - Seuls l'auteur et le destinataire voient une demande : tout autre utilisateur, quel que soit
 *   son rôle (admin compris), reçoit 404. Ce contrôle passe avant tous les autres.
 * - Un participant qui tente l'action de l'autre (l'auteur qui accepte, le destinataire qui
 *   retire) reçoit 403.
 * - L'état de la demande (en attente) est vérifié par les Actions, sous verrou (409).
 * Les propositions sont autorisées par ProjectPolicy::propose.
 */
class MatchRequestPolicy
{
    private const NOT_FOUND = 'Demande introuvable.';

    public function view(User $user, MatchRequest $matchRequest): Response
    {
        return $matchRequest->involves($user) ? Response::allow() : Response::denyAsNotFound(self::NOT_FOUND);
    }

    public function accept(User $user, MatchRequest $matchRequest): Response
    {
        $response = $this->recipientOnly($user, $matchRequest, 'Seul le destinataire peut accepter cette demande.');

        if ($response->denied()) {
            return $response;
        }

        return $matchRequest->author_id !== null && UserBlock::existsBetween($user->id, $matchRequest->author_id)
            ? Response::deny('Vous ne pouvez pas accepter cette demande.')
            : Response::allow();
    }

    public function decline(User $user, MatchRequest $matchRequest): Response
    {
        return $this->recipientOnly($user, $matchRequest, 'Seul le destinataire peut refuser cette demande.');
    }

    public function withdraw(User $user, MatchRequest $matchRequest): Response
    {
        $response = $this->view($user, $matchRequest);

        if ($response->denied()) {
            return $response;
        }

        return $matchRequest->isAuthor($user) ? Response::allow() : Response::deny('Seul l\'auteur peut retirer cette demande.');
    }

    public function report(User $user, MatchRequest $matchRequest): Response
    {
        return $this->recipientOnly($user, $matchRequest, 'Seul le destinataire peut signaler cette demande.');
    }

    public function block(User $user, MatchRequest $matchRequest): Response
    {
        return $this->recipientOnly($user, $matchRequest, 'Seul le destinataire peut bloquer l\'auteur de cette demande.');
    }

    /**
     * Contacter un prestataire (POST /providers/{slug}/contact) : particuliers et entrepreneurs,
     * sans blocage entre les deux utilisateurs.
     */
    public function contact(User $user, int $providerUserId): Response
    {
        if (! $user->hasAnyRole([AccountType::Particulier->value, AccountType::Entrepreneur->value])) {
            return Response::deny('Seuls les particuliers et les entrepreneurs peuvent contacter un prestataire.');
        }

        return UserBlock::existsBetween($user->id, $providerUserId)
            ? Response::deny('Vous ne pouvez pas contacter ce prestataire.')
            : Response::allow();
    }

    private function recipientOnly(User $user, MatchRequest $matchRequest, string $message): Response
    {
        $response = $this->view($user, $matchRequest);

        if ($response->denied()) {
            return $response;
        }

        return $matchRequest->isRecipient($user) ? Response::allow() : Response::deny($message);
    }
}
