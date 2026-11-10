<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Support\PendingMatchRequest;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * L'auteur retire sa demande en attente. Aucun email : le destinataire la voit simplement
 * passer au statut "withdrawn". active_key est libérée.
 */
class WithdrawMatchRequest
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(MatchRequest $matchRequest): MatchRequest
    {
        return DB::transaction(function () use ($matchRequest): MatchRequest {
            $matchRequest = PendingMatchRequest::lock($matchRequest);

            $matchRequest->forceFill([
                'status' => MatchRequestStatus::Withdrawn,
                'withdrawn_at' => now(),
                'active_key' => null,
            ])->save();

            return $matchRequest;
        });
    }
}
