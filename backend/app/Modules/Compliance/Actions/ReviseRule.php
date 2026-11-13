<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Nouvelle version d'une règle vérifiée : copie en brouillon (replaces_rule_id), à modifier puis
 * à vérifier. La règle vérifiée reste en vigueur jusqu'à la vérification de ce brouillon, qui la
 * retire alors : le contenu modifié n'est jamais montré sans vérification, et le calendrier des
 * utilisateurs ne présente aucun trou.
 */
class ReviseRule
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, ComplianceRule $rule): ComplianceRule
    {
        return DB::transaction(function () use ($admin, $rule): ComplianceRule {
            $rule = RuleAdministration::lock($rule, RuleStatus::Verified, null, 'Seule une règle vérifiée peut recevoir une nouvelle version.');

            $open = ComplianceRule::query()
                ->where('replaces_rule_id', $rule->id)
                ->where('status', RuleStatus::Draft->value)
                ->exists();

            if ($open) {
                throw new ConflictHttpException('Une nouvelle version de cette règle est déjà en brouillon : modifiez-la.');
            }

            $draft = new ComplianceRule;
            $draft->forceFill([
                'uuid' => (string) Str::uuid(),
                'country' => $rule->country,
                'kind' => $rule->kind,
                'code' => $rule->code,
                'valid_from' => $rule->valid_from->toDateString(),
                'valid_until' => $rule->valid_until?->toDateString(),
                'status' => RuleStatus::Draft,
                'version' => 1,
                'title' => $rule->title,
                'summary' => $rule->summary,
                'conditions' => $rule->conditions,
                'payload' => $rule->payload,
                'source_url' => $rule->source_url,
                'source_label' => $rule->source_label,
                'internal_notes' => $rule->internal_notes,
                'replaces_rule_id' => $rule->id,
                'is_demo' => $rule->is_demo,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ])->save();

            RuleAdministration::record($admin, 'revised', $draft);

            return $draft;
        });
    }
}
