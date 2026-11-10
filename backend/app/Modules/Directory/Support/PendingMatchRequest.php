<?php

namespace App\Modules\Directory\Support;

use App\Modules\Directory\Models\MatchRequest;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Verrouille une demande pour la faire changer de statut. À appeler dans une transaction.
 *
 * Seule une demande en attente change de statut : sinon 409. Le verrou empêche deux
 * transitions simultanées (par exemple une acceptation et un retrait) de réussir toutes les deux.
 */
final class PendingMatchRequest
{
    /**
     * @throws ConflictHttpException
     */
    public static function lock(MatchRequest $matchRequest): MatchRequest
    {
        $locked = MatchRequest::query()->whereKey($matchRequest->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw new ConflictHttpException('Cette demande a déjà été traitée.');
        }

        return $locked;
    }
}
