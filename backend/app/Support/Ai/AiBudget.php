<?php

namespace App\Support\Ai;

use App\Models\AiUsage;
use App\Models\User;
use App\Support\Ai\Exceptions\AiQuotaExceeded;
use App\Support\Ai\Exceptions\AiUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Plafonds de coût de l'IA (décision 62) : par utilisateur et par mois, et au global par mois
 * et par jour (config/ai.php, budgets).
 *
 * Avant l'appel, reserve() verrouille les compteurs (ai_budgets), vérifie que le coût maximal
 * de l'appel (entrée estimée et sortie maximale) tient sous chaque plafond, puis le réserve :
 * deux appels simultanés ne peuvent pas dépasser un plafond. Après l'appel, settle() remplace la
 * réservation par le coût réel (zéro pour un échec sans réponse) et met à jour les totaux par
 * jour (ai_usage_stats, sans utilisateur).
 */
final class AiBudget
{
    private const GLOBAL_MONTH = 'global:month';

    private const GLOBAL_DAY = 'global:day';

    public function __construct(private AiBudgetAlerts $alerts) {}

    /**
     * @throws AiUnavailable plafond global atteint (reason "capacity")
     * @throws AiQuotaExceeded plafond de l'utilisateur atteint (scope "budget")
     */
    public function reserve(User $user, AiRequest $request, string $provider, string $model): AiUsage
    {
        $estimate = AiPricing::cost($model, $request->estimatedInputTokens(), $request->maxOutputTokens);
        $now = CarbonImmutable::now('UTC');
        $counters = self::counters($user, $now);
        self::ensureCounters($counters);

        return DB::transaction(function () use ($user, $request, $provider, $model, $estimate, $now, $counters): AiUsage {
            $limits = $this->lockCounters($counters);

            foreach ($limits as $scope => [$row, $limit]) {
                if ((int) $row->spent_micros + (int) $row->reserved_micros + $estimate <= $limit) {
                    continue;
                }

                if ($scope === 'user') {
                    throw new AiQuotaExceeded('budget', $now->startOfMonth()->addMonth());
                }

                throw new AiUnavailable('capacity');
            }

            foreach ($limits as [$row]) {
                DB::table('ai_budgets')->where('id', $row->id)->increment('reserved_micros', $estimate);
            }

            $usage = new AiUsage;
            $usage->forceFill([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'feature' => $request->feature,
                'provider' => $provider,
                'model' => $model,
                'status' => AiUsage::STATUS_RESERVED,
                'reserved_micros' => $estimate,
            ])->save();

            return $usage;
        });
    }

    /**
     * Remplace la réservation par le coût réel. Un échec sans réponse (reason non nul, jetons
     * nuls) ne coûte rien.
     */
    public function settle(AiUsage $usage, ?string $failureReason, int $inputTokens = 0, int $outputTokens = 0, ?string $model = null): void
    {
        $model ??= $usage->model;
        $cost = AiPricing::cost($model, $inputTokens, $outputTokens);
        $created = CarbonImmutable::parse($usage->created_at ?? now())->utc();

        DB::transaction(function () use ($usage, $failureReason, $inputTokens, $outputTokens, $model, $cost, $created): void {
            $keys = [[self::GLOBAL_MONTH, $created->format('Y-m')], [self::GLOBAL_DAY, $created->format('Y-m-d')]];

            if ($usage->user_id !== null) {
                $keys[] = ['user:'.$usage->user_id, $created->format('Y-m')];
            }

            foreach ($keys as [$scope, $period]) {
                $row = DB::table('ai_budgets')->where('scope_key', $scope)->where('period', $period)->lockForUpdate()->first();

                if ($row === null) {
                    continue;
                }

                DB::table('ai_budgets')->where('id', $row->id)->update([
                    'reserved_micros' => max(0, (int) $row->reserved_micros - $usage->reserved_micros),
                    'spent_micros' => (int) $row->spent_micros + $cost,
                    'updated_at' => now(),
                ]);
            }

            $usage->forceFill([
                'status' => $failureReason === null ? AiUsage::STATUS_SUCCEEDED : AiUsage::STATUS_FAILED,
                'failure_reason' => $failureReason,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_micros' => $cost,
            ])->save();
        });

        // Après la transaction : voir recordStats().
        $this->recordStats($created->toDateString(), $usage->feature, $model, $failureReason !== null, $inputTokens, $outputTokens, $cost);

        if ($cost > 0) {
            $this->alerts->check($created);
        }
    }

    /**
     * Dépense du mois en cours au global, en micro-unités, et son plafond.
     *
     * @return array{spent: int, reserved: int, limit: int}
     */
    public function globalMonth(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now('UTC');
        $row = DB::table('ai_budgets')->where('scope_key', self::GLOBAL_MONTH)->where('period', $at->format('Y-m'))->first();

        return [
            'spent' => (int) ($row->spent_micros ?? 0),
            'reserved' => (int) ($row->reserved_micros ?? 0),
            'limit' => AiPricing::centsToMicros((int) config('ai.budgets.global_monthly_cents')),
        ];
    }

    /**
     * Compteurs d'un appel, toujours dans le même ordre (global du mois, global du jour,
     * utilisateur) pour éviter les interblocages.
     *
     * @return array<string, array{0: string, 1: string, 2: int|null, 3: int}> scope, période, user_id, plafond en cents
     */
    private static function counters(User $user, CarbonImmutable $now): array
    {
        return [
            'global_month' => [self::GLOBAL_MONTH, $now->format('Y-m'), null, (int) config('ai.budgets.global_monthly_cents')],
            'global_day' => [self::GLOBAL_DAY, $now->format('Y-m-d'), null, (int) config('ai.budgets.global_daily_cents')],
            'user' => ['user:'.$user->id, $now->format('Y-m'), $user->id, (int) config('ai.budgets.user_monthly_cents')],
        ];
    }

    /**
     * Crée les compteurs absents, **hors de la transaction qui les verrouille** : sous InnoDB,
     * INSERT IGNORE pose un verrou partagé sur la ligne existante, gardé jusqu'à la fin de la
     * transaction. Dans la transaction, deux réservations simultanées tenaient chacune ce verrou
     * partagé puis attendaient le verrou exclusif de l'autre (interblocage à chaque appel
     * concurrent). En autocommit, le verrou est rendu dès la fin de l'instruction.
     *
     * @param  array<string, array{0: string, 1: string, 2: int|null, 3: int}>  $counters
     */
    private static function ensureCounters(array $counters): void
    {
        foreach ($counters as [$scope, $period, $userId]) {
            DB::table('ai_budgets')->insertOrIgnore([
                'scope_key' => $scope,
                'period' => $period,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Verrouille les compteurs (déjà créés par ensureCounters()), dans leur ordre.
     *
     * @param  array<string, array{0: string, 1: string, 2: int|null, 3: int}>  $counters
     * @return array<string, array{0: object, 1: int}>
     */
    private function lockCounters(array $counters): array
    {
        $locked = [];

        foreach ($counters as $name => [$scope, $period, , $cents]) {
            $row = DB::table('ai_budgets')->where('scope_key', $scope)->where('period', $period)->lockForUpdate()->first()
                ?? throw new RuntimeException("Compteur de budget d'IA absent : {$scope} {$period}.");
            $locked[$name] = [$row, AiPricing::centsToMicros($cents)];
        }

        return $locked;
    }

    /**
     * Totaux par jour, sans utilisateur. Appelé hors de toute transaction : deux instructions
     * en autocommit (création au besoin, puis incréments atomiques), qui ne gardent aucun
     * verrou. Dans la transaction de settle(), INSERT IGNORE puis UPDATE sur une même ligne
     * faisaient s'interbloquer deux appels simultanés, comme les compteurs de budget.
     */
    private function recordStats(string $day, string $feature, string $model, bool $failed, int $inputTokens, int $outputTokens, int $cost): void
    {
        DB::table('ai_usage_stats')->insertOrIgnore([
            'day' => $day,
            'feature' => $feature,
            'model' => $model,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_usage_stats')
            ->where('day', $day)->where('feature', $feature)->where('model', $model)
            ->update([
                'requests' => DB::raw('requests + 1'),
                'failures' => DB::raw('failures + '.($failed ? 1 : 0)),
                'input_tokens' => DB::raw('input_tokens + '.max(0, $inputTokens)),
                'output_tokens' => DB::raw('output_tokens + '.max(0, $outputTokens)),
                'cost_micros' => DB::raw('cost_micros + '.max(0, $cost)),
                'updated_at' => now(),
            ]);
    }
}
