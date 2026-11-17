<?php

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleCatalog;
use Carbon\CarbonInterface;

/**
 * Action publique du module Compliance : taux de TVA vérifiés d'un pays, en vigueur un jour
 * donné (règles de type vat_rate), en données simples. Utilisée par le module Marketplace pour
 * les lignes de devis : aucun taux n'est jamais écrit dans le code.
 *
 * Seules les règles utilisables sortent (vérifiées, et jamais de démonstration hors du poste
 * local). Une règle vérifiée a toujours un taux, un libellé et un champ d'application
 * (RuleContent::missingFields).
 *
 * @phpstan-type Translated array{fr: string|null, en: string|null}
 * @phpstan-type VatRate array{code: string, version: int, basis_points: int, label: Translated, scope: Translated, mention: Translated|null, client_certification: Translated|null, exclusive: bool, valid_from: string, valid_until: string|null}
 */
class GetVatRates
{
    /**
     * @return list<VatRate>
     */
    public function handle(string $country, CarbonInterface $day): array
    {
        return ComplianceRule::query()
            ->usable()
            ->where('country', $country)
            ->where('kind', RuleKind::VatRate->value)
            ->validOn($day)
            ->orderBy('code')
            ->get()
            ->map(fn (ComplianceRule $rule): array => self::present($rule))
            ->values()
            ->all();
    }

    /**
     * Le taux d'un code en vigueur ce jour-là, ou null.
     *
     * @return VatRate|null
     */
    public function find(string $country, string $code, CarbonInterface $day): ?array
    {
        $rule = RuleCatalog::find($country, $code, $day);

        return $rule !== null && $rule->kind === RuleKind::VatRate ? self::present($rule) : null;
    }

    /**
     * @return VatRate
     */
    private static function present(ComplianceRule $rule): array
    {
        $payload = $rule->payload;

        return [
            'code' => $rule->code,
            'version' => $rule->version,
            'basis_points' => (int) ($payload['basis_points'] ?? 0),
            'label' => self::translated($payload['label'] ?? null) ?? ['fr' => null, 'en' => null],
            'scope' => self::translated($payload['scope'] ?? null) ?? ['fr' => null, 'en' => null],
            'mention' => self::translated($payload['mention'] ?? null),
            'client_certification' => self::translated($payload['client_certification'] ?? null),
            'exclusive' => (bool) ($payload['exclusive'] ?? false),
            'valid_from' => $rule->valid_from->toDateString(),
            'valid_until' => $rule->valid_until?->toDateString(),
        ];
    }

    /**
     * @return Translated|null
     */
    private static function translated(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['fr'] ?? null)) {
            return null;
        }

        return ['fr' => $value['fr'], 'en' => is_string($value['en'] ?? null) ? $value['en'] : null];
    }
}
