<?php

namespace App\Modules\Compliance\Actions;

use App\Http\Middleware\EnsureModerator;
use App\Models\User;
use App\Modules\Compliance\Notifications\RuleCoverageNotification;
use App\Modules\Compliance\Support\RuleCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Mise à jour annuelle des règles (tâche compliance:check-rule-coverage, chaque jour) : entre le
 * 1er octobre et le 30 novembre (compliance.coverage), si des règles de l'année suivante manquent
 * ou sont à revérifier, les admins (email vérifié, 2FA confirmée) reçoivent un récapitulatif, au
 * plus une fois tous les digest_days jours. Hors de cette période, rien n'est fait.
 *
 * La même analyse est disponible à tout moment : GET /admin/compliance/coverage.
 */
class CheckRuleCoverage
{
    public const LAST_SENT_KEY = 'compliance.coverage_digest.last_sent_at';

    /**
     * @return int nombre d'admins prévenus
     */
    public function handle(): int
    {
        $today = CarbonImmutable::now();
        $start = CarbonImmutable::parse($today->year.'-'.config('compliance.coverage.window_start'))->startOfDay();
        $end = CarbonImmutable::parse($today->year.'-'.config('compliance.coverage.window_end'))->endOfDay();

        if ($today->lessThan($start) || $today->greaterThan($end)) {
            return 0;
        }

        $lock = Cache::lock(self::LAST_SENT_KEY.':lock', 120);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $lastSent = Cache::get(self::LAST_SENT_KEY);

            if (is_int($lastSent) && $today->getTimestamp() - $lastSent < (int) config('compliance.coverage.digest_days') * 86400) {
                return 0;
            }

            $year = $today->year + 1;
            $report = RuleCoverage::report($year);

            if (! RuleCoverage::needsAttention($report)) {
                return 0;
            }

            $admins = User::role(EnsureModerator::ADMIN_ROLE)
                ->whereNotNull('email_verified_at')
                ->whereNotNull('two_factor_secret')
                ->whereNotNull('two_factor_confirmed_at')
                ->get();

            foreach ($admins as $admin) {
                RuleCoverageNotification::sendTo($admin, new RuleCoverageNotification($year, $report));
            }

            Cache::forever(self::LAST_SENT_KEY, $today->getTimestamp());

            return $admins->count();
        } finally {
            $lock->release();
        }
    }
}
