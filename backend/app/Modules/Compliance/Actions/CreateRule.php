<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleAdministration;
use App\Modules\Compliance\Support\RuleContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Création d'une règle par un admin : toujours en brouillon, jamais visible des utilisateurs
 * avant sa vérification. Le contenu est reconstruit par RuleContent.
 */
class CreateRule
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(User $admin, string $country, RuleKind $kind, string $code, array $validated): ComplianceRule
    {
        return DB::transaction(function () use ($admin, $country, $kind, $code, $validated): ComplianceRule {
            $rule = new ComplianceRule;
            $rule->forceFill([
                ...RuleContent::normalize($kind, $validated),
                'uuid' => (string) Str::uuid(),
                'country' => $country,
                'kind' => $kind,
                'code' => $code,
                'status' => RuleStatus::Draft,
                'version' => 1,
                'is_demo' => false,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ])->save();

            RuleAdministration::record($admin, 'created', $rule);

            return $rule;
        });
    }
}
