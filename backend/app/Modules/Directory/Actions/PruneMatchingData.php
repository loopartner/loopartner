<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\ReportStatus;
use App\Modules\Directory\Models\AbuseReport;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\MatchRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Minimisation des données de la mise en relation (tâche quotidienne matching:prune). Les durées
 * sont dans config/security.php (retention), en mois :
 *
 * - conversations dont un participant a supprimé son compte depuis plus de
 *   orphaned_conversations_months : supprimées avec leurs messages et leur demande ;
 * - demandes refusées, retirées ou expirées depuis plus de closed_match_requests_months
 *   (date de leur dernière modification, c'est-à-dire de leur passage à ce statut) : supprimées.
 *   Les demandes acceptées restent tant que leur conversation existe ;
 * - signalements de plus de abuse_reports_months : la copie du contenu signalé et le
 *   commentaire sont effacés ; les signalements clos depuis plus de cette durée sont supprimés.
 */
class PruneMatchingData
{
    /**
     * @return array{conversations: int, match_requests: int, reports_purged: int, reports_deleted: int}
     */
    public function handle(): array
    {
        return [
            'conversations' => $this->pruneOrphanedConversations(),
            'match_requests' => MatchRequest::query()
                ->whereIn('status', MatchRequestStatus::closedValues())
                ->where('updated_at', '<=', self::cutoff('closed_match_requests_months'))
                ->delete(),
            'reports_purged' => AbuseReport::query()
                ->where('created_at', '<=', self::cutoff('abuse_reports_months'))
                ->where(fn ($query) => $query->whereNotNull('content_snapshot')->orWhereNotNull('comment'))
                ->update(['content_snapshot' => null, 'comment' => null, 'updated_at' => now()]),
            'reports_deleted' => AbuseReport::query()
                ->where('status', ReportStatus::Closed->value)
                ->where('closed_at', '<=', self::cutoff('abuse_reports_months'))
                ->delete(),
        ];
    }

    private function pruneOrphanedConversations(): int
    {
        $deleted = 0;

        Conversation::query()
            ->where('orphaned_at', '<=', self::cutoff('orphaned_conversations_months'))
            ->chunkById(200, function (Collection $conversations) use (&$deleted): void {
                DB::transaction(function () use ($conversations, &$deleted): void {
                    $matchRequestIds = $conversations->pluck('match_request_id')->filter()->all();

                    // Messages et participants supprimés par les clés étrangères (cascade).
                    $deleted += Conversation::query()->whereKey($conversations->modelKeys())->delete();
                    MatchRequest::query()->whereKey($matchRequestIds)->delete();
                });
            });

        return $deleted;
    }

    private static function cutoff(string $setting): CarbonInterface
    {
        return now()->subMonths((int) config('security.retention.'.$setting));
    }
}
