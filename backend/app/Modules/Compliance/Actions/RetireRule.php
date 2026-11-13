<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleAdministration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Retrait d'une règle vérifiée (erreur découverte, règle abrogée), avec un motif écrit dans le
 * journal d'audit. Elle n'est plus montrée ; les échéances à venir qu'elle avait produites sont
 * retirées des calendriers au prochain recalcul, sauf celles que l'utilisateur a déjà traitées.
 */
class RetireRule
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, ComplianceRule $rule, int $version, string $reason): ComplianceRule
    {
        return DB::transaction(function () use ($admin, $rule, $version, $reason): ComplianceRule {
            $rule = RuleAdministration::lock($rule, RuleStatus::Verified, $version, 'Seule une règle vérifiée peut être retirée (un brouillon se supprime).');

            $rule->forceFill([
                'status' => RuleStatus::Retired,
                'retired_at' => now(),
                'retirement_reason' => $reason,
            ])->save();

            RuleAdministration::record($admin, 'retired', $rule, $reason);
            RuleAdministration::refreshCalendarsAfterCommit($rule);

            return $rule;
        });
    }
}
