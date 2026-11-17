<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Notifications\MatchingNotification;
use App\Modules\Directory\Notifications\MatchRequestAcceptedNotification;
use App\Modules\Directory\Support\PendingMatchRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Le destinataire accepte une demande en attente : la conversation à deux participants est
 * ouverte dans la même transaction, puis l'auteur est prévenu par email.
 *
 * - Demande déjà traitée : 409 (PendingMatchRequest).
 * - Proposition sur un projet qui n'est plus visible (clos, expiré, supprimé) : 409.
 * - Le blocage et le rôle du destinataire sont vérifiés par MatchRequestPolicy::accept.
 * - Une demande acceptée reste active (active_key) : pas de seconde demande entre les mêmes
 *   personnes sur le même projet, la conversation existe déjà.
 */
class AcceptMatchRequest
{
    /**
     * @param  bool  $notify  false quand le module appelant prévient lui-même l'auteur
     *                        (confirmation d'un rendez-vous réservé sur la page publique)
     *
     * @throws ConflictHttpException
     */
    public function handle(MatchRequest $matchRequest, bool $notify = true): MatchRequest
    {
        return DB::transaction(function () use ($matchRequest, $notify): MatchRequest {
            $matchRequest = PendingMatchRequest::lock($matchRequest);

            if ($matchRequest->type === MatchRequestType::Proposal && ($matchRequest->project === null || ! $matchRequest->project->isLive())) {
                throw new ConflictHttpException('Ce projet n\'est plus publié : la proposition ne peut plus être acceptée.');
            }

            if ($matchRequest->author === null || $matchRequest->recipient === null) {
                throw new ConflictHttpException('Cette demande a déjà été traitée.');
            }

            $now = now();

            $matchRequest->forceFill([
                'status' => MatchRequestStatus::Accepted,
                'responded_at' => $now,
            ])->save();

            $conversation = new Conversation;
            $conversation->forceFill([
                'uuid' => (string) Str::uuid(),
                'match_request_id' => $matchRequest->id,
                'last_activity_at' => $now,
            ])->save();

            foreach ([$matchRequest->author_id, $matchRequest->recipient_id] as $userId) {
                $conversation->participants()->make()->forceFill(['user_id' => $userId])->save();
            }

            $matchRequest->setRelation('conversation', $conversation);

            if ($notify) {
                MatchingNotification::sendTo($matchRequest->author, new MatchRequestAcceptedNotification($matchRequest));
            }

            return $matchRequest;
        });
    }
}
