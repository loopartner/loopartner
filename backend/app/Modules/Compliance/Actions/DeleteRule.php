<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleAdministration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Suppression d'un brouillon (jamais vérifié, donc jamais montré). Une règle vérifiée ou retirée
 * est gardée : elle a pu produire des échéances, et son historique sert d'audit (409).
 */
class DeleteRule
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, ComplianceRule $rule): void
    {
        DB::transaction(function () use ($admin, $rule): void {
            $rule = RuleAdministration::lock($rule, RuleStatus::Draft, null, 'Seul un brouillon peut être supprimé : retirez une règle vérifiée.');

            RuleAdministration::record($admin, 'deleted', $rule);
            $rule->delete();
        });
    }
}
