<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Support\Disclaimer;
use App\Modules\Compliance\Support\Entitlements;
use App\Modules\Compliance\Support\RuleCatalog;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entreprise vue par son propriétaire (seul à y avoir accès). Le numéro d'entreprise et le
 * numéro de TVA sont renvoyés à leur propriétaire seulement ; jamais d'identifiant interne.
 *
 * - locked : entreprise en trop après la fin du premium (lecture, export et suppression seuls).
 * - rules_status : "outdated" si le statut juridique de l'entreprise n'a plus de règle vérifiée
 *   en vigueur (le calendrier n'est plus complété) ; le frontend avertit l'utilisateur.
 *
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;
        $locale = Locale::fromRequest($request);
        $today = CarbonImmutable::now(RuleCatalog::timezone($company->country));
        $legalForm = RuleCatalog::find($company->country, $company->legal_form_code, $today);

        return [
            'uuid' => $company->uuid,
            'name' => $company->name,
            'country' => $company->country,
            'currency' => RuleCatalog::currency($company->country),
            'legal_form' => [
                'code' => $company->legal_form_code,
                'title' => $legalForm?->titleIn($locale),
            ],
            'activity_types' => $company->activity_types,
            'vat_regime' => $company->vat_regime,
            'created_on' => $company->created_on->toDateString(),
            'fiscal_year_end' => $company->fiscal_year_end,
            'options' => $company->options,
            'registration_number' => $company->registration_number,
            'vat_number' => $company->vat_number,
            'locked' => app(Entitlements::class)->isLocked($company),
            'rules_status' => $legalForm === null ? 'outdated' : 'ok',
            'calendar_changed_at' => $company->calendar_changed_at?->toIso8601ZuluString(),
            'created_at' => $company->created_at?->toIso8601ZuluString(),
            'updated_at' => $company->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['disclaimer' => Disclaimer::forRequest($request)];
    }
}
