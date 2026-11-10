<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\MatchRequest;

/**
 * Traite les données de mise en relation d'un utilisateur qui supprime son compte (RGPD).
 * Appelée dans la transaction de DeleteAccount (événement AccountDeleting), avant la
 * suppression de la ligne users.
 *
 * - Demandes non acceptées (envoyées ou reçues, quel que soit leur statut) : supprimées. Aucun
 *   échange n'a eu lieu ; les conserver n'apporterait rien à l'autre partie.
 * - Conversations : conservées pour l'autre participant, sans lien avec l'identité du compte
 *   supprimé (les clés étrangères passent à null à la suppression de la ligne users). Elles
 *   passent en lecture seule et sont purgées après security.retention.orphaned_conversations_months
 *   (orphaned_at). Si l'autre participant a lui aussi supprimé son compte, la conversation et
 *   sa demande sont supprimées tout de suite.
 * - Demandes acceptées restantes : active_key libérée (elle contient l'identifiant supprimé).
 * - Blocages : supprimés par la clé étrangère (cascade). Signalements : conservés pour la
 *   modération, reporter_id et reported_user_id passent à null.
 */
class ForgetUserMatching
{
    public function handle(User $user): void
    {
        MatchRequest::query()
            ->involving($user)
            ->where('status', '!=', MatchRequestStatus::Accepted->value)
            ->delete();

        Conversation::query()
            ->forUser($user)
            ->with('participants')
            ->get()
            ->each(function (Conversation $conversation) use ($user): void {
                if ($conversation->otherParticipant($user)?->user_id === null) {
                    $matchRequestId = $conversation->match_request_id;
                    $conversation->delete();
                    MatchRequest::query()->whereKey($matchRequestId)->delete();

                    return;
                }

                $conversation->forceFill(['orphaned_at' => now()])->save();
            });

        MatchRequest::query()->involving($user)->update(['active_key' => null]);
    }
}
