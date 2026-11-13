<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleContent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Modification d'un brouillon (PATCH /admin/compliance/rules/{uuid}) : version lue obligatoire,
 * seules les clés envoyées changent (le payload envoyé remplace le payload entier). Le pays, le
 * type et le code ne changent jamais (422), ni les champs fixés par la plateforme.
 */
class UpdateRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rule = $this->rule();

        return [
            'country' => ['prohibited'],
            'kind' => ['prohibited'],
            'code' => ['prohibited'],
            ...RuleContent::rules($rule->kind, true),
            ...StoreRuleRequest::platformFields(['version']),
            // Ici, « version » est la version lue par l'admin (contrôle de version).
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Règle visée (404 si elle n'existe pas, avant toute validation).
     */
    public function rule(): ComplianceRule
    {
        $rule = ComplianceRule::query()->where('uuid', (string) $this->route('uuid'))->first();
        abort_if($rule === null, 404, 'Règle introuvable.');

        return $rule;
    }
}
