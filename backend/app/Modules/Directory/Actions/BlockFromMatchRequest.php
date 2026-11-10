<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Models\MatchRequest;
use Illuminate\Support\Facades\DB;

/**
 * Le destinataire d'une demande en bloque l'auteur, avant même toute conversation (spam). Une
 * demande encore en attente est refusée, sans email à l'auteur.
 */
class BlockFromMatchRequest
{
    public function __construct(
        private BlockUser $blockUser,
        private DeclineMatchRequest $declineMatchRequest,
    ) {}

    public function handle(User $recipient, MatchRequest $matchRequest): void
    {
        if ($matchRequest->author_id === null) {
            return;
        }

        DB::transaction(function () use ($recipient, $matchRequest): void {
            $this->blockUser->handle($recipient, (int) $matchRequest->author_id);

            if ($matchRequest->isPending()) {
                $this->declineMatchRequest->handle($matchRequest, notify: false);
            }
        });
    }
}
