<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;

/**
 * Un prestataire propose ses services sur un projet publié, avec un message et une estimation
 * de prix facultative. Le destinataire est le propriétaire du projet.
 *
 * L'autorisation (profil prestataire publié, projet visible, absence de blocage) est vérifiée
 * avant par ProjectPolicy::propose.
 */
class SendProposal
{
    public function __construct(private CreateMatchRequest $createMatchRequest) {}

    /**
     * @param  array{message: string, price_amount?: int|null, price_currency?: string|null}  $data
     */
    public function handle(User $provider, Project $project, array $data): MatchRequest
    {
        return $this->createMatchRequest->handle(
            $provider,
            MatchRequestType::Proposal,
            $project->user_id,
            $project,
            $data,
            'Vous avez déjà une proposition en cours sur ce projet.',
        );
    }
}
