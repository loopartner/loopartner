<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\ManageJourney;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Http\Requests\StartJourneyRequest;
use App\Modules\Compliance\Http\Resources\JourneyResource;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Journey;
use App\Modules\Compliance\Support\Disclaimer;
use App\Modules\Compliance\Support\RuleCatalog;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Parcours types (règles journey vérifiées) et parcours suivis par une entreprise.
 */
class JourneyController extends Controller
{
    private const RELATIONS = ['rule', 'tasks.journey.rule', 'tasks.deadline'];

    /**
     * Parcours proposés pour un pays (règles vérifiées en vigueur).
     */
    public function templates(Request $request, string $country): JsonResponse
    {
        Gate::authorize('use', Company::class);
        $country = strtoupper($country);
        $today = CarbonImmutable::now(RuleCatalog::timezone($country));
        $locale = Locale::fromRequest($request);

        $journeys = RuleCatalog::overlapping($country, RuleKind::Journey, $today, $today)->map(fn (ComplianceRule $rule): array => [
            'code' => $rule->code,
            'title' => $rule->titleIn($locale),
            'summary' => $rule->summaryIn($locale),
            'steps' => count((array) ($rule->payload['steps'] ?? [])),
            'source' => ['url' => $rule->source_url, 'label' => $rule->source_label],
            'verified_at' => $rule->last_verified_at?->toIso8601ZuluString(),
        ])->values();

        return response()->json(['data' => $journeys->all(), 'disclaimer' => Disclaimer::forRequest($request)]);
    }

    public function index(Request $request, string $company): AnonymousResourceCollection
    {
        $model = CompanyController::find($company, 'view');
        $journeys = Journey::query()->where('company_id', $model->id)->with(self::RELATIONS)->orderBy('id')->get();

        return JourneyResource::collection($journeys)->additional(['disclaimer' => Disclaimer::forRequest($request)]);
    }

    public function store(StartJourneyRequest $request, ManageJourney $manageJourney): JsonResponse
    {
        $journey = $manageJourney->start($request->company(), $request->string('journey')->toString());

        return (new JourneyResource($journey->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function destroy(string $company, string $journey, ManageJourney $manageJourney): Response
    {
        $model = CompanyController::find($company, 'update');
        $found = Journey::query()->where('company_id', $model->id)->where('uuid', $journey)->first();
        abort_if($found === null, 404, 'Parcours introuvable.');

        $manageJourney->delete($model, $found);

        return response()->noContent();
    }
}
