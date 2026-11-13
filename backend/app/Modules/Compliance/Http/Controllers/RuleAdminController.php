<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Compliance\Actions\CreateRule;
use App\Modules\Compliance\Actions\DeleteRule;
use App\Modules\Compliance\Actions\RetireRule;
use App\Modules\Compliance\Actions\ReviseRule;
use App\Modules\Compliance\Actions\UpdateRule;
use App\Modules\Compliance\Actions\VerifyRule;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Http\Requests\ListRulesRequest;
use App\Modules\Compliance\Http\Requests\RuleDecisionRequest;
use App\Modules\Compliance\Http\Requests\StoreRuleRequest;
use App\Modules\Compliance\Http\Requests\UpdateRuleRequest;
use App\Modules\Compliance\Http\Resources\AdminRuleResource;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleCoverage;
use App\Support\Pagination;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/**
 * Administration des règles (/admin/compliance/…) : admins avec la double authentification de
 * session (EnsureModerator ; 404 pour tout autre rôle, 403 sans 2FA). Les admins ne voient
 * jamais les données des entreprises : ils ne gèrent que les règles.
 */
class RuleAdminController extends Controller
{
    public function index(ListRulesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = ComplianceRule::query()->with('replaces')->orderBy('country')->orderBy('code')->orderBy('valid_from')->orderBy('id');

        foreach (['country', 'kind', 'status', 'code'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (filled($filters['year'] ?? null)) {
            $year = (int) $filters['year'];
            $query->overlapping(CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31));
        }

        return AdminRuleResource::collection($query->paginate(Pagination::perPage($request))->withQueryString());
    }

    public function store(StoreRuleRequest $request, CreateRule $createRule): JsonResponse
    {
        $validated = $request->validated();
        $rule = $createRule->handle(
            self::admin($request),
            (string) $validated['country'],
            RuleKind::from((string) $validated['kind']),
            (string) $validated['code'],
            Arr::except($validated, ['country', 'kind', 'code']),
        );

        return (new AdminRuleResource($rule))->withHistory()->response()->setStatusCode(201);
    }

    public function show(string $uuid): AdminRuleResource
    {
        return (new AdminRuleResource(self::find($uuid)->load('replaces')))->withHistory();
    }

    public function update(UpdateRuleRequest $request, UpdateRule $updateRule): AdminRuleResource
    {
        $rule = $updateRule->handle(self::admin($request), $request->rule(), $request->integer('version'), Arr::except($request->validated(), ['version']));

        return (new AdminRuleResource($rule->load('replaces')))->withHistory();
    }

    public function revise(Request $request, string $uuid, ReviseRule $reviseRule): JsonResponse
    {
        $draft = $reviseRule->handle(self::admin($request), self::find($uuid));

        return (new AdminRuleResource($draft->load('replaces')))->withHistory()->response()->setStatusCode(201);
    }

    public function verify(RuleDecisionRequest $request, string $uuid, VerifyRule $verifyRule): AdminRuleResource
    {
        $rule = $verifyRule->handle(self::admin($request), self::find($uuid), $request->integer('version'), $request->string('verification_note')->toString());

        return (new AdminRuleResource($rule->load('replaces')))->withHistory();
    }

    public function retire(RuleDecisionRequest $request, string $uuid, RetireRule $retireRule): AdminRuleResource
    {
        $rule = $retireRule->handle(self::admin($request), self::find($uuid), $request->integer('version'), $request->string('reason')->toString());

        return (new AdminRuleResource($rule->load('replaces')))->withHistory();
    }

    public function destroy(Request $request, string $uuid, DeleteRule $deleteRule): Response
    {
        $deleteRule->handle(self::admin($request), self::find($uuid));

        return response()->noContent();
    }

    /**
     * Couverture des règles pour une année (par défaut l'année suivante) : mise à jour annuelle.
     */
    public function coverage(Request $request): JsonResponse
    {
        $validated = $request->validate(['year' => ['sometimes', 'integer', 'between:2000,2100']]);
        $year = (int) ($validated['year'] ?? now()->year + 1);
        $report = RuleCoverage::report($year);

        return response()->json([
            'data' => $report,
            'meta' => ['year' => $year, 'needs_attention' => RuleCoverage::needsAttention($report)],
        ]);
    }

    private static function find(string $uuid): ComplianceRule
    {
        $rule = ComplianceRule::query()->where('uuid', $uuid)->first();
        abort_if($rule === null, 404, 'Règle introuvable.');

        return $rule;
    }

    private static function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
