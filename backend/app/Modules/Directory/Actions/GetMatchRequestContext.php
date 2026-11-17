<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\UserBlock;

/**
 * Action publique du module Directory : contexte d'une demande de mise en relation vue par l'un
 * de ses deux participants, en données simples (module Marketplace : devis). Null si la demande
 * n'existe pas ou si l'utilisateur n'y participe pas : l'appelant répond 404.
 *
 * - provider_id : le prestataire (auteur d'une proposition, destinataire d'un contact) ;
 *   client_id : le porteur de projet. L'un ou l'autre vaut null si le compte a été supprimé.
 * - client_role : particulier ou entrepreneur (rôle du porteur de projet), ou null.
 * - project : projet visé par une proposition, ou cité par un contact (null sinon).
 * - accepted : la demande est acceptée (la conversation est ouverte, le nom du porteur révélé).
 *
 * @phpstan-type MatchRequestContext array{match_request_id: int, uuid: string, accepted: bool, provider_id: int|null, client_id: int|null, client_role: string|null, client_name: string|null, project: array{id: int, uuid: string, title: string, remote_ok: bool, owner_id: int}|null, blocked: bool}
 */
class GetMatchRequestContext
{
    /**
     * @return MatchRequestContext|null
     */
    public function handle(string $uuid, User $viewer): ?array
    {
        return $this->present(MatchRequest::query()->with(['project', 'author.roles', 'recipient.roles'])->where('uuid', $uuid)->first(), $viewer);
    }

    /**
     * Même contexte, la demande étant désignée par son identifiant interne (gardé par le module
     * appelant dans sa propre table).
     *
     * @return MatchRequestContext|null
     */
    public function forId(int $id, User $viewer): ?array
    {
        return $this->present(MatchRequest::query()->with(['project', 'author.roles', 'recipient.roles'])->whereKey($id)->first(), $viewer);
    }

    /**
     * @return MatchRequestContext|null
     */
    private function present(?MatchRequest $request, User $viewer): ?array
    {
        if ($request === null || ! $request->involves($viewer)) {
            return null;
        }

        [$provider, $client] = $request->type === MatchRequestType::Proposal
            ? [$request->author, $request->recipient]
            : [$request->recipient, $request->author];

        $clientRole = null;

        foreach ([AccountType::Particulier, AccountType::Entrepreneur] as $type) {
            if ($client?->hasRole($type->value)) {
                $clientRole = $type->value;
            }
        }

        $project = $request->project;

        return [
            'match_request_id' => $request->id,
            'uuid' => $request->uuid,
            'accepted' => $request->status === MatchRequestStatus::Accepted,
            'provider_id' => $provider?->id,
            'client_id' => $client?->id,
            'client_role' => $clientRole,
            'client_name' => $request->status === MatchRequestStatus::Accepted ? $client?->name : null,
            'project' => $project === null ? null : [
                'id' => $project->id,
                'uuid' => $project->uuid,
                'title' => $project->title,
                'remote_ok' => $project->remote_ok,
                'owner_id' => $project->user_id,
            ],
            'blocked' => $provider !== null && $client !== null && UserBlock::existsBetween($provider->id, $client->id),
        ];
    }
}
