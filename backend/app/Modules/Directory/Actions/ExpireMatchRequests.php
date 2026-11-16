<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fait expirer (tâche quotidienne match-requests:expire) :
 * - les demandes en attente depuis plus de MatchRequest::EXPIRATION_DAYS jours ;
 * - les propositions en attente dont le projet n'est plus visible (clos, expiré ou supprimé).
 *
 * Requêtes UPDATE groupées : rapides, et sans risque si la tâche est relancée. Aucun email.
 */
class ExpireMatchRequests
{
    /**
     * @return int nombre de demandes expirées
     */
    public function handle(): int
    {
        $now = now();

        $expired = MatchRequest::query()
            ->where('status', MatchRequestStatus::Pending->value)
            ->where('expires_at', '<=', $now)
            ->update(self::expiredValues($now));

        return $expired + MatchRequest::query()
            ->where('status', MatchRequestStatus::Pending->value)
            ->where('type', MatchRequestType::Proposal->value)
            ->where(fn (Builder $query) => $query
                ->whereNull('project_id')
                ->orWhereNotIn('project_id', Project::query()->browsable()->select('id')))
            ->update(self::expiredValues($now));
    }

    /**
     * Colonnes modifiées par une expiration (active_key libérée).
     *
     * @return array<string, mixed>
     */
    public static function expiredValues(CarbonInterface $now): array
    {
        return [
            'status' => MatchRequestStatus::Expired->value,
            'expired_at' => $now,
            'active_key' => null,
            'updated_at' => $now,
        ];
    }
}
