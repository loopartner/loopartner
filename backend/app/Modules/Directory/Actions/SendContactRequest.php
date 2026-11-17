<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;

/**
 * Un particulier ou un entrepreneur contacte un prestataire depuis son profil public, avec un
 * message et, facultativement, un de ses propres projets publiés.
 *
 * Le prestataire est désigné par son identifiant d'utilisateur, obtenu par l'Action publique
 * FindPublishedProvider du module Accounts. L'autorisation est vérifiée avant par
 * MatchRequestPolicy::contact, et le projet par StoreContactRequest.
 */
class SendContactRequest
{
    public function __construct(private CreateMatchRequest $createMatchRequest) {}

    /**
     * @param  array{message: string}  $data
     * @param  bool  $notify  false : le module appelant prévient lui-même le prestataire
     */
    public function handle(User $author, int $providerUserId, ?Project $project, array $data, bool $notify = true): MatchRequest
    {
        return $this->createMatchRequest->handle(
            $author,
            MatchRequestType::Contact,
            $providerUserId,
            $project,
            ['message' => $data['message']],
            'Vous avez déjà une demande en cours avec ce prestataire.',
            $notify,
        );
    }
}
