<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleAdministration;
use App\Modules\Compliance\Support\RuleContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Modification d'un brouillon (version lue obligatoire, 409 sinon). Une règle vérifiée ne se
 * modifie jamais sur place (409) : POST …/revise crée un brouillon qui la remplacera une fois
 * vérifié (ReviseRule). Le payload envoyé remplace le payload entier.
 */
class UpdateRule
{
    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ConflictHttpException
     * @throws ValidationException
     */
    public function handle(User $admin, ComplianceRule $rule, int $version, array $validated): ComplianceRule
    {
        return DB::transaction(function () use ($admin, $rule, $version, $validated): ComplianceRule {
            $rule = RuleAdministration::lock($rule, RuleStatus::Draft, $version, 'Seul un brouillon se modifie : créez une nouvelle version de cette règle (POST …/revise).');

            $attributes = RuleContent::normalize($rule->kind, $validated, $rule);
            $from = (string) ($attributes['valid_from'] ?? $rule->valid_from->toDateString());
            $until = array_key_exists('valid_until', $attributes) ? $attributes['valid_until'] : $rule->valid_until?->toDateString();

            if (is_string($until) && $until < $from) {
                throw ValidationException::withMessages(['valid_until' => 'La date de fin doit être postérieure ou égale à la date de début.']);
            }

            $rule->forceFill([
                ...$attributes,
                'version' => $rule->version + 1,
                'updated_by' => $admin->id,
            ])->save();

            RuleAdministration::record($admin, 'updated', $rule);

            return $rule;
        });
    }
}
