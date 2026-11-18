<?php

namespace App\Support\Ai;

use App\Models\AiUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Minimisation de la brique d'IA (décision 65 ; ai:prune, chaque nuit) :
 * - appels encore « réservés » depuis plus d'une heure (processus interrompu) : réglés en
 *   échec sans coût, pour libérer leur réservation dans les plafonds ;
 * - journal des appels (ai_usages) et compteurs par utilisateur (ai_budgets user:*) plus
 *   anciens que security.retention.ai_usages_months : supprimés. Les compteurs globaux et les
 *   totaux par jour (sans utilisateur) restent.
 */
final class PruneAiData
{
    public function __construct(private AiBudget $budget) {}

    /**
     * @return array{abandoned: int, usages: int, budgets: int}
     */
    public function handle(): array
    {
        $abandoned = 0;

        AiUsage::query()
            ->where('status', AiUsage::STATUS_RESERVED)
            ->where('created_at', '<', now()->subHour())
            ->orderBy('id')
            ->each(function (AiUsage $usage) use (&$abandoned): void {
                $this->budget->settle($usage, 'abandoned');
                $abandoned++;
            });

        $cutoff = CarbonImmutable::now('UTC')->subMonths((int) config('security.retention.ai_usages_months'));

        return [
            'abandoned' => $abandoned,
            'usages' => AiUsage::query()->where('created_at', '<', $cutoff)->delete(),
            'budgets' => DB::table('ai_budgets')->where('scope_key', 'like', 'user:%')->where('period', '<', $cutoff->format('Y-m'))->delete(),
        ];
    }
}
