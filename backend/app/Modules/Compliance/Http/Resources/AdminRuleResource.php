<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Models\AdminAuditLog;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleAdministration;
use App\Modules\Compliance\Support\RuleContent;
use App\Support\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Règle vue par un admin : contenu complet, notes internes, champs encore vides, vérification.
 * Jamais d'identifiant interne ni l'identité des admins (le journal d'audit les garde).
 *
 * @mixin ComplianceRule
 */
class AdminRuleResource extends JsonResource
{
    private bool $withHistory = false;

    public function withHistory(): self
    {
        $this->withHistory = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ComplianceRule $rule */
        $rule = $this->resource;

        $data = [
            'uuid' => $rule->uuid,
            'country' => $rule->country,
            'kind' => $rule->kind->value,
            'code' => $rule->code,
            'status' => $rule->status->value,
            'version' => $rule->version,
            'valid_from' => $rule->valid_from->toDateString(),
            'valid_until' => $rule->valid_until?->toDateString(),
            'title' => $rule->title,
            'summary' => $rule->summary,
            'conditions' => $rule->conditions,
            'payload' => $rule->payload,
            'source_url' => $rule->source_url,
            'source_label' => $rule->source_label,
            'internal_notes' => $rule->internal_notes,
            'missing_fields' => RuleContent::missingFields($rule),
            'verification' => $rule->last_verified_at === null ? null : [
                'verified_at' => $rule->last_verified_at->toIso8601ZuluString(),
                'note' => $rule->verification_note,
            ],
            'replaces' => $rule->replaces?->uuid,
            'is_demo' => $rule->is_demo,
            'retired_at' => $rule->retired_at?->toIso8601ZuluString(),
            'retirement_reason' => $rule->retirement_reason,
            'created_at' => $rule->created_at?->toIso8601ZuluString(),
            'updated_at' => $rule->updated_at?->toIso8601ZuluString(),
        ];

        if ($this->withHistory) {
            // Décisions passées, sans l'identité des admins.
            $data['history'] = AdminAudit::historyOf(RuleAdministration::AUDIT_SUBJECT, $rule->uuid)
                ->map(fn (AdminAuditLog $entry): array => [
                    'action' => $entry->action,
                    'version' => $entry->version,
                    'reason' => $entry->reason,
                    'at' => $entry->created_at?->toIso8601ZuluString(),
                ])->values()->all();
        }

        return $data;
    }
}
