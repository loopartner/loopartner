<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleAdministration;
use App\Modules\Compliance\Support\RuleContent;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Vérification d'un brouillon : la règle devient visible des utilisateurs.
 *
 * - Version lue obligatoire (409 si le brouillon a changé depuis).
 * - Contenu complet obligatoire : aucune valeur « à compléter », une source officielle (422,
 *   avec la liste des champs vides).
 * - Note de vérification obligatoire (validée par la requête) : qui a vérifié (cabinet ou
 *   professionnel), quand, sur quel document ou quelle source. Elle est gardée sur la règle et
 *   dans le journal d'audit.
 * - Quatre yeux (compliance.require_distinct_verifier, désactivé par défaut) : l'admin qui a
 *   modifié le brouillon en dernier ne peut pas le vérifier (403, journalisé).
 * - Une seule version vérifiée d'un code à la fois sur une période (409 en cas de
 *   chevauchement), sauf celle que ce brouillon remplace : elle est retirée.
 */
class VerifyRule
{
    /**
     * @throws AccessDeniedHttpException
     * @throws ConflictHttpException
     * @throws ValidationException
     */
    public function handle(User $admin, ComplianceRule $rule, int $version, string $note): ComplianceRule
    {
        return DB::transaction(function () use ($admin, $rule, $version, $note): ComplianceRule {
            $rule = RuleAdministration::lock($rule, RuleStatus::Draft, $version, 'Seul un brouillon peut être vérifié.');

            if (config('compliance.require_distinct_verifier') === true && $rule->updated_by === $admin->id) {
                SecurityLog::record('compliance.rule_self_verification_denied', ['user_id' => $admin->id, 'rule' => $rule->uuid], 'warning');

                throw new AccessDeniedHttpException('Une règle doit être vérifiée par un autre admin que celui qui l\'a modifiée en dernier.');
            }

            $missing = RuleContent::missingFields($rule);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'rule' => 'Cette règle est incomplète et ne peut pas être vérifiée. Champs à compléter : '.implode(', ', $missing).'.',
                ]);
            }

            $overlapping = ComplianceRule::query()
                ->where('country', $rule->country)
                ->where('code', $rule->code)
                ->where('status', RuleStatus::Verified->value)
                ->whereKeyNot($rule->replaces_rule_id ?? 0)
                ->overlapping($rule->valid_from, $rule->valid_until)
                ->exists();

            if ($overlapping) {
                throw new ConflictHttpException('Une autre version vérifiée de cette règle couvre déjà tout ou partie de cette période : retirez-la ou ajustez les dates de validité.');
            }

            if ($rule->replaces_rule_id !== null) {
                $replaced = ComplianceRule::query()->whereKey($rule->replaces_rule_id)->lockForUpdate()->first();

                if ($replaced !== null && $replaced->status === RuleStatus::Verified) {
                    $replaced->forceFill([
                        'status' => RuleStatus::Retired,
                        'retired_at' => now(),
                        'retirement_reason' => 'Remplacée par une nouvelle version vérifiée.',
                    ])->save();

                    RuleAdministration::record($admin, 'retired', $replaced, 'Remplacée par la version '.$rule->uuid.'.');
                }
            }

            $rule->forceFill([
                'status' => RuleStatus::Verified,
                'last_verified_at' => now(),
                'verification_note' => $note,
                'verified_by' => $admin->id,
            ])->save();

            RuleAdministration::record($admin, 'verified', $rule, $note);

            return $rule;
        });
    }
}
