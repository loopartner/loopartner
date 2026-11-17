<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use Carbon\CarbonInterface;

/**
 * Minimisation des devis (tâche quotidienne marketplace:prune, décision 47). Durées en mois
 * dans config/security.php (retention) :
 *
 * - brouillons sans modification depuis marketplace_drafts_months : supprimés ;
 * - devis refusés, retirés, expirés ou remplacés depuis marketplace_closed_quotes_months (date
 *   de leur dernière modification, c'est-à-dire de leur passage à ce statut) : supprimés.
 *
 * Les devis acceptés restent tant que leur conversation existe : ils disparaissent avec elle
 * (clé étrangère en cascade, purge matching:prune des conversations orphelines).
 */
class PruneMarketplaceData
{
    /**
     * @return array{drafts: int, closed: int}
     */
    public function handle(): array
    {
        return [
            'drafts' => Quote::query()
                ->where('status', QuoteStatus::Draft->value)
                ->where('updated_at', '<=', self::cutoff('marketplace_drafts_months'))
                ->delete(),
            'closed' => Quote::query()
                ->whereIn('status', QuoteStatus::closedValues())
                ->where('updated_at', '<=', self::cutoff('marketplace_closed_quotes_months'))
                ->delete(),
        ];
    }

    private static function cutoff(string $setting): CarbonInterface
    {
        return now()->subMonths((int) config('security.retention.'.$setting));
    }
}
