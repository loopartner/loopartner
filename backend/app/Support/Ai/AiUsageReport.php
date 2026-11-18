<?php

namespace App\Support\Ai;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Suivi des coûts de l'IA pour les admins (décision 34) : totaux par jour, fonctionnalité et
 * modèle (ai_usage_stats, sans utilisateur), dépense et plafonds du mois, nombre d'utilisateurs
 * distincts. **Aucune identité, aucun contenu.** Montants en micro-unités de la devise du
 * fournisseur.
 */
final class AiUsageReport
{
    public function __construct(private AiGateway $gateway, private AiBudget $budget) {}

    /**
     * @return array<string, mixed>
     */
    public function month(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $from->addMonth();
        $budget = $this->budget->globalMonth($from);

        $days = DB::table('ai_usage_stats')
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<', $to->toDateString())
            ->orderBy('day')->orderBy('feature')->orderBy('model')
            ->get()
            ->map(fn (object $row): array => [
                'day' => substr((string) $row->day, 0, 10),
                'feature' => (string) $row->feature,
                'model' => (string) $row->model,
                'requests' => (int) $row->requests,
                'failures' => (int) $row->failures,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost_micros' => (int) $row->cost_micros,
            ])
            ->values();

        return [
            'month' => $from->format('Y-m'),
            'currency' => (string) config('ai.currency'),
            'driver' => (string) config('ai.driver'),
            'available' => $this->gateway->available(),
            'unavailable_reason' => $this->gateway->unavailableReason(),
            'model' => $this->gateway->model(),
            'budget' => [
                'spent_micros' => $budget['spent'],
                'reserved_micros' => $budget['reserved'],
                'limit_micros' => $budget['limit'],
                'percent' => $budget['limit'] > 0 ? intdiv($budget['spent'] * 100, $budget['limit']) : null,
                'daily_limit_micros' => AiPricing::centsToMicros((int) config('ai.budgets.global_daily_cents')),
                'user_monthly_limit_micros' => AiPricing::centsToMicros((int) config('ai.budgets.user_monthly_cents')),
            ],
            'totals' => [
                'requests' => $days->sum('requests'),
                'failures' => $days->sum('failures'),
                'input_tokens' => $days->sum('input_tokens'),
                'output_tokens' => $days->sum('output_tokens'),
                'cost_micros' => $days->sum('cost_micros'),
                'users' => DB::table('ai_usages')->where('created_at', '>=', $from)->where('created_at', '<', $to)->whereNotNull('user_id')->distinct()->count('user_id'),
            ],
            'by_feature' => $days->groupBy('feature')->map(fn ($rows, $feature): array => [
                'feature' => (string) $feature,
                'requests' => $rows->sum('requests'),
                'cost_micros' => $rows->sum('cost_micros'),
            ])->values()->all(),
            'days' => $days->all(),
        ];
    }
}
