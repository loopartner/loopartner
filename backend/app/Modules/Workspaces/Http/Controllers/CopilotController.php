<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Workspaces\Actions\ApplySuggestion;
use App\Modules\Workspaces\Actions\GenerateSuggestion;
use App\Modules\Workspaces\Enums\SuggestionKind;
use App\Modules\Workspaces\Http\Requests\ApplySuggestionRequest;
use App\Modules\Workspaces\Http\Requests\GenerateRequest;
use App\Modules\Workspaces\Http\Resources\SuggestionResource;
use App\Modules\Workspaces\Models\CopilotSuggestion;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\CopilotPayload;
use App\Modules\Workspaces\Support\CopilotQuota;
use App\Modules\Workspaces\Support\CopilotTexts;
use App\Modules\Workspaces\Support\Workspaces;
use App\Support\Ai\AiGateway;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Copilote de projet (décisions 33 et 61 à 66) : état et textes, prévisualisation exacte du
 * contenu envoyé, génération, suggestions (lecture, application, rejet). Workspace d'un autre
 * utilisateur : 404.
 */
class CopilotController extends Controller
{
    public function __construct(private AiGateway $gateway, private Workspaces $workspaces, private CopilotQuota $quota) {}

    /**
     * GET /me/copilot : disponibilité, fournisseur, textes à afficher et quotas.
     */
    public function status(Request $request): JsonResponse
    {
        Gate::authorize('use', Workspace::class);
        $user = $this->user($request);
        $locale = Locale::fromRequest($request);
        $provider = (string) ($this->gateway->providerLabel() ?? config('ai.providers.mistral.label'));

        return response()->json(['data' => [
            'available' => $this->gateway->available(),
            'provider' => $this->gateway->providerLabel(),
            'email_verified' => $user->hasVerifiedEmail(),
            'notice' => ['version' => CopilotTexts::NOTICE_VERSION, 'text' => CopilotTexts::notice($locale, $provider)],
            'disclaimer' => CopilotTexts::disclaimer($locale),
            'sensitive_data_warning' => CopilotTexts::sensitiveDataWarning($locale),
            'quota' => $this->quota->status($user),
        ]])->header('Vary', 'Accept-Language');
    }

    /**
     * GET …/copilot/preview?kind=roadmap|specification&step=&category= : contenu exact qui
     * serait envoyé (expurgé), sans aucun appel.
     */
    public function preview(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->own($request, $uuid);
        $validated = $request->validate([
            'kind' => ['sometimes', 'string', 'in:roadmap,specification'],
            'step' => ['sometimes', 'nullable', 'string', 'uuid'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $kind = SuggestionKind::from($validated['kind'] ?? 'roadmap');
        $step = $this->step($workspace, $validated['step'] ?? null);
        $category = $this->category($validated['category'] ?? null) ?? $step?->category;
        $payload = CopilotPayload::build($this->user($request), $workspace, $kind, Locale::fromRequest($request), $step, $category);

        return response()->json(['data' => [
            'available' => $this->gateway->available(),
            'provider' => $this->gateway->providerLabel(),
            'sent' => $payload['content'],
            'redactions' => $payload['redactions'],
            'notice_version' => CopilotTexts::NOTICE_VERSION,
        ]]);
    }

    public function roadmap(GenerateRequest $request, GenerateSuggestion $generate): JsonResponse
    {
        $workspace = $request->workspace();
        $suggestion = $generate->handle($this->user($request), $workspace, SuggestionKind::Roadmap, Locale::fromRequest($request));

        return $this->resource($workspace, $suggestion)->response()->setStatusCode(201);
    }

    public function specification(GenerateRequest $request, GenerateSuggestion $generate): JsonResponse
    {
        $workspace = $request->workspace();
        $step = $this->step($workspace, $request->validated('step'));
        $category = $this->category($request->validated('category'));
        $suggestion = $generate->handle($this->user($request), $workspace, SuggestionKind::Specification, Locale::fromRequest($request), $step, $category);

        return $this->resource($workspace, $suggestion)->response()->setStatusCode(201);
    }

    public function index(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->own($request, $uuid);
        $suggestions = CopilotSuggestion::query()->where('workspace_id', $workspace->id)->with('step')->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'data' => $suggestions->map(fn (CopilotSuggestion $suggestion): array => $this->resource($workspace, $suggestion)->resolve($request))->values()->all(),
        ]);
    }

    public function show(Request $request, string $uuid, string $suggestion): SuggestionResource
    {
        $workspace = $this->own($request, $uuid);

        return $this->resource($workspace, $this->suggestion($workspace, $suggestion));
    }

    public function apply(ApplySuggestionRequest $request, string $uuid, string $suggestion, ApplySuggestion $apply): SuggestionResource
    {
        $workspace = $request->workspace();
        /** @var list<int>|null $steps */
        $steps = $request->validated('steps');
        $applied = $apply->apply($this->user($request), $workspace, $this->suggestion($workspace, $suggestion), $steps);

        return $this->resource($workspace, $applied);
    }

    public function dismiss(Request $request, string $uuid, string $suggestion, ApplySuggestion $apply): SuggestionResource
    {
        $workspace = $this->own($request, $uuid);

        return $this->resource($workspace, $apply->dismiss($this->suggestion($workspace, $suggestion)));
    }

    private function resource(Workspace $workspace, CopilotSuggestion $suggestion): SuggestionResource
    {
        $label = config('ai.providers.'.$suggestion->provider.'.label');

        return new SuggestionResource($suggestion->loadMissing('step'), $workspace->country, is_string($label) ? $label : null);
    }

    private function own(Request $request, string $uuid): Workspace
    {
        Gate::authorize('use', Workspace::class);

        return $this->workspaces->own($this->user($request), $uuid);
    }

    private function suggestion(Workspace $workspace, string $uuid): CopilotSuggestion
    {
        return CopilotSuggestion::query()->where('workspace_id', $workspace->id)->where('uuid', $uuid)->first()
            ?? throw new NotFoundHttpException('Suggestion introuvable.');
    }

    private function step(Workspace $workspace, mixed $uuid): ?Step
    {
        if (! is_string($uuid)) {
            return null;
        }

        return Step::query()->where('workspace_id', $workspace->id)->where('uuid', $uuid)->with('category')->first()
            ?? throw new NotFoundHttpException('Étape introuvable.');
    }

    private function category(mixed $slug): ?ServiceCategory
    {
        return is_string($slug) ? ServiceCategory::query()->active()->where('slug', $slug)->first() : null;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
