<?php

namespace App\Support\Ai;

use App\Http\Middleware\EnsureModerator;
use App\Models\User;
use App\Notifications\AiBudgetAlert;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Alertes de budget de l'IA (décision 62) : après chaque appel réglé, si la dépense du mois
 * franchit un palier (config/ai.php, budgets.alert_levels : 80 et 100), les admins avec 2FA
 * confirmée reçoivent une alerte, **une seule fois par palier et par mois** (UPDATE
 * conditionnel sur ai_budgets.alerted_level).
 */
final class AiBudgetAlerts
{
    public function check(CarbonImmutable $at): void
    {
        $period = $at->format('Y-m');
        $row = DB::table('ai_budgets')->where('scope_key', 'global:month')->where('period', $period)->first();
        $limit = AiPricing::centsToMicros((int) config('ai.budgets.global_monthly_cents'));

        if ($row === null || $limit <= 0) {
            return;
        }

        $reached = 0;

        foreach ((array) config('ai.budgets.alert_levels') as $level) {
            if ((int) $row->spent_micros * 100 >= $limit * (int) $level) {
                $reached = max($reached, (int) $level);
            }
        }

        if ($reached <= (int) $row->alerted_level) {
            return;
        }

        $claimed = DB::table('ai_budgets')
            ->where('id', $row->id)
            ->where('alerted_level', '<', $reached)
            ->update(['alerted_level' => $reached, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        SecurityLog::record('ai.budget_alert', ['user_id' => null, 'period' => $period, 'level' => $reached], 'warning');

        User::role(EnsureModerator::ADMIN_ROLE)
            ->whereNotNull('two_factor_confirmed_at')
            ->get()
            ->each(fn (User $admin) => $admin->notify(new AiBudgetAlert($period, $reached)));
    }
}
