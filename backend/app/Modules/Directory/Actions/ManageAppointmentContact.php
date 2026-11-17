<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\MatchRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Action publique du module Directory : demande de contact qui accompagne une réservation sur
 * la page publique d'un prestataire (module Appointments, décision 52).
 *
 * - submit() : la demande de contact est créée avec le motif comme message, avec toutes les
 *   règles d'un contact (blocage 403, demande déjà active 409, plafond de demandes en attente
 *   409), mais **sans email** : le module Appointments prévient lui-même le prestataire.
 * - accept() : la demande est acceptée (conversation ouverte, nom du client révélé), sans
 *   l'email « demande acceptée » (le client reçoit la confirmation du rendez-vous).
 * - decline(), withdraw(), expire() : sans email, et sans effet si la demande n'est plus en
 *   attente (elle a pu être traitée depuis la messagerie).
 * - acceptedBetween() : uuid d'une demande acceptée entre un client et un prestataire, quel
 *   qu'en soit le sens (proposition ou contact), ou null.
 *
 * Les demandes sont désignées par leur identifiant interne, gardé par le module appelant.
 */
class ManageAppointmentContact
{
    public function __construct(
        private SendContactRequest $sendContactRequest,
        private AcceptMatchRequest $acceptMatchRequest,
        private DeclineMatchRequest $declineMatchRequest,
        private WithdrawMatchRequest $withdrawMatchRequest,
    ) {}

    /**
     * @return array{match_request_id: int, uuid: string}
     *
     * @throws AccessDeniedHttpException
     * @throws ConflictHttpException
     */
    public function submit(User $client, int $providerUserId, string $message): array
    {
        $matchRequest = $this->sendContactRequest->handle($client, $providerUserId, null, ['message' => $message], notify: false);

        return ['match_request_id' => $matchRequest->id, 'uuid' => $matchRequest->uuid];
    }

    /**
     * @throws ConflictHttpException demande qui n'est plus en attente
     */
    public function accept(int $matchRequestId): void
    {
        $this->acceptMatchRequest->handle(MatchRequest::query()->findOrFail($matchRequestId), notify: false);
    }

    public function decline(int $matchRequestId): void
    {
        $this->whenPending($matchRequestId, fn (MatchRequest $request) => $this->declineMatchRequest->handle($request, notify: false));
    }

    public function withdraw(int $matchRequestId): void
    {
        $this->whenPending($matchRequestId, fn (MatchRequest $request) => $this->withdrawMatchRequest->handle($request));
    }

    public function expire(int $matchRequestId): void
    {
        MatchRequest::query()
            ->whereKey($matchRequestId)
            ->where('status', MatchRequestStatus::Pending->value)
            ->update(ExpireMatchRequests::expiredValues(now()));
    }

    public function acceptedBetween(int $clientId, int $providerUserId): ?string
    {
        $uuid = MatchRequest::query()
            ->where('status', MatchRequestStatus::Accepted->value)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $pair) => $pair->where('author_id', $clientId)->where('recipient_id', $providerUserId))
                ->orWhere(fn (Builder $pair) => $pair->where('author_id', $providerUserId)->where('recipient_id', $clientId)))
            ->orderByDesc('id')
            ->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * @param  callable(MatchRequest): mixed  $action
     */
    private function whenPending(int $matchRequestId, callable $action): void
    {
        DB::transaction(function () use ($matchRequestId, $action): void {
            $request = MatchRequest::query()->whereKey($matchRequestId)->lockForUpdate()->first();

            if ($request !== null && $request->isPending()) {
                $action($request);
            }
        });
    }
}
