<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Enums\ActivityType;
use App\Modules\Compliance\Enums\ComplianceOption;
use App\Modules\Compliance\Enums\VatRegime;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\Disclaimer;
use App\Modules\Compliance\Support\RuleCatalog;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Questionnaire de démarrage : pays disponibles et réponses possibles, tirés des seules règles
 * VÉRIFIÉES pour l'année en cours (aucun brouillon). Réservé aux prestataires et aux
 * entrepreneurs connectés : les règles vérifiées ont un coût pour l'équipe.
 */
class CatalogController extends Controller
{
    public function countries(Request $request): JsonResponse
    {
        Gate::authorize('use', Company::class);

        $countries = collect(RuleCatalog::configuredCountries())
            ->filter(fn (string $country): bool => RuleCatalog::isAvailable($country, CarbonImmutable::now(RuleCatalog::timezone($country))->year))
            ->map(fn (string $country): array => ['code' => $country, 'currency' => RuleCatalog::currency($country)])
            ->values();

        return response()->json(['data' => $countries->all(), 'disclaimer' => Disclaimer::forRequest($request)]);
    }

    public function questionnaire(Request $request, string $country): JsonResponse
    {
        Gate::authorize('use', Company::class);

        $country = strtoupper($country);
        $year = CarbonImmutable::now(RuleCatalog::timezone($country))->year;
        abort_unless(RuleCatalog::isAvailable($country, $year), 404, 'Ce pays n\'est pas encore pris en charge.');

        $locale = Locale::fromRequest($request);

        $legalForms = RuleCatalog::legalFormsThroughout($country, $year)->map(fn (ComplianceRule $rule): array => [
            'code' => $rule->code,
            'title' => $rule->titleIn($locale),
            'summary' => $rule->summaryIn($locale),
            'activity_types' => array_values((array) ($rule->payload['activity_types'] ?? [])),
            'vat_regimes' => array_values((array) ($rule->payload['vat_regimes'] ?? [])),
            'options' => collect((array) ($rule->payload['options'] ?? []))
                ->map(fn ($key): ?ComplianceOption => ComplianceOption::tryFrom((string) $key))
                ->filter()
                ->map(fn (ComplianceOption $option): array => [
                    'key' => $option->value,
                    'label' => $option->label($locale),
                    'type' => $option->choices() === null ? 'boolean' : 'choice',
                    'choices' => $option->choices(),
                    'default' => $option->defaultValue(),
                ])->values()->all(),
            'source' => ['url' => $rule->source_url, 'label' => $rule->source_label],
            'verified_at' => $rule->last_verified_at?->toIso8601ZuluString(),
        ])->values();

        return response()->json([
            'data' => [
                'country' => $country,
                'currency' => RuleCatalog::currency($country),
                'year' => $year,
                'legal_forms' => $legalForms->all(),
                'activity_types' => array_map(fn (ActivityType $type): array => ['code' => $type->value, 'label' => $type->label($locale)], ActivityType::cases()),
                'vat_regimes' => array_map(fn (VatRegime $regime): array => ['code' => $regime->value, 'label' => $regime->label($locale)], VatRegime::cases()),
            ],
            'disclaimer' => Disclaimer::forRequest($request),
        ]);
    }
}
