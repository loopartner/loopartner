<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Notifications\MatchingNotification;
use App\Modules\Directory\Notifications\MatchRequestDeclinedNotification;
use App\Modules\Directory\Support\PendingMatchRequest;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Le destinataire refuse une demande en attente. L'auteur est prévenu par email, sauf quand le
 * refus accompagne un blocage ($notify = false) : on ne signale pas un blocage à un spammeur.
 * active_key est libérée : l'auteur pourra envoyer une nouvelle demande (sauf blocage).
 */
class DeclineMatchRequest
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(MatchRequest $matchRequest, bool $notify = true): MatchRequest
    {
        return DB::transaction(function () use ($matchRequest, $notify): MatchRequest {
            $matchRequest = PendingMatchRequest::lock($matchRequest);

            $matchRequest->forceFill([
                'status' => MatchRequestStatus::Declined,
                'responded_at' => now(),
                'active_key' => null,
            ])->save();

            if ($notify && $matchRequest->author !== null) {
                MatchingNotification::sendTo($matchRequest->author, new MatchRequestDeclinedNotification($matchRequest));
            }

            return $matchRequest;
        });
    }
}
