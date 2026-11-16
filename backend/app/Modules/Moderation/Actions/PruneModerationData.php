<?php

namespace App\Modules\Moderation\Actions;

use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\DecisionMeasure;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Support\Moderation\ModerationTargets;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Minimisation des données de la modération (tâche quotidienne moderation:prune ; durées en mois
 * dans config/security.php, retention ; décision G) :
 *
 * - moderation_notices_months (12) après la clôture d'un signalement (ou après sa réception
 *   s'il est encore ouvert) : identité et email du signalant, explication, précision sur
 *   l'emplacement, copie du contenu et jeton de suivi sont effacés ;
 * - moderation_records_months (24) après la fin du délai de recours ou la levée (la plus
 *   tardive), sans recours en attente : la décision, ses recours et ses signalements sont
 *   supprimés ; les signalements classés sans décision le sont 24 mois après leur clôture.
 *
 * Une restriction encore en vigueur garde sa décision (son exposé des motifs reste consultable)
 * tant que le contenu existe.
 */
class PruneModerationData
{
    /**
     * @return array{notices_purged: int, decisions_deleted: int, notices_deleted: int}
     */
    public function handle(): array
    {
        $personalCutoff = self::cutoff('moderation_notices_months');
        $recordsCutoff = self::cutoff('moderation_records_months');

        $purged = Notice::query()
            ->whereNull('personal_data_purged_at')
            ->where(fn (Builder $query) => $query
                ->where('closed_at', '<=', $personalCutoff)
                ->orWhere(fn (Builder $open) => $open->whereNull('closed_at')->where('created_at', '<=', $personalCutoff)))
            ->update([
                'reporter_name' => null,
                'reporter_email' => null,
                'reporter_email_hash' => null,
                'reporter_locale' => null,
                'explanation' => null,
                'location_detail' => null,
                'content_label' => null,
                'content_snapshot' => null,
                'tracking_token_hash' => null,
                'personal_data_purged_at' => now(),
                'updated_at' => now(),
            ]);

        $decisionsDeleted = 0;
        $noticesDeleted = 0;

        Decision::query()
            ->where('appeal_deadline_at', '<=', $recordsCutoff)
            ->where(fn (Builder $query) => $query->whereNull('lifted_at')->orWhere('lifted_at', '<=', $recordsCutoff))
            ->whereDoesntHave('appeals', fn (Builder $appeals) => $appeals->where('status', AppealStatus::Pending->value))
            ->chunkById(200, function (Collection $decisions) use (&$decisionsDeleted, &$noticesDeleted): void {
                $ids = $decisions->filter(fn (Decision $decision): bool => ! self::stillInForce($decision))->modelKeys();

                if ($ids === []) {
                    return;
                }

                DB::transaction(function () use ($ids, &$decisionsDeleted, &$noticesDeleted): void {
                    $noticesDeleted += Notice::query()->whereIn('decision_id', $ids)->delete();
                    // Recours supprimés par la clé étrangère (cascade).
                    $decisionsDeleted += Decision::query()->whereKey($ids)->delete();
                });
            });

        $noticesDeleted += Notice::query()
            ->whereNull('decision_id')
            ->where('status', '!=', NoticeStatus::Open->value)
            ->where('closed_at', '<=', $recordsCutoff)
            ->delete();

        return ['notices_purged' => $purged, 'decisions_deleted' => $decisionsDeleted, 'notices_deleted' => $noticesDeleted];
    }

    /**
     * Restriction réversible toujours en vigueur sur un contenu qui existe encore.
     */
    private static function stillInForce(Decision $decision): bool
    {
        if ($decision->measure !== DecisionMeasure::RestrictVisibility || ! $decision->reversible || $decision->lifted_at !== null) {
            return false;
        }

        return ModerationTargets::has($decision->target_type)
            && ModerationTargets::get($decision->target_type)->inspect($decision->target_ref)?->restrictedBy === $decision->uuid;
    }

    private static function cutoff(string $key): CarbonInterface
    {
        return now()->subMonths((int) config('security.retention.'.$key));
    }
}
