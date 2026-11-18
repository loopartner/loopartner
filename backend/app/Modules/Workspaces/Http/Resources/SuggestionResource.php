<?php

namespace App\Modules\Workspaces\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use App\Modules\Workspaces\Enums\SuggestionKind;
use App\Modules\Workspaces\Models\CopilotSuggestion;
use App\Modules\Workspaces\Support\CopilotTexts;
use App\Modules\Workspaces\Support\SpecificationSections;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Suggestion du copilote vue par le propriétaire du workspace : contenu déjà nettoyé,
 * catégories présentées (slug, nom, recherche de prestataires), et objet "ai" (contenu généré,
 * fournisseur, modèle, avertissement) sur chaque réponse (décision 66).
 *
 * @mixin CopilotSuggestion
 */
class SuggestionResource extends JsonResource
{
    public function __construct(CopilotSuggestion $resource, private ?string $country = null, private ?string $providerLabel = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CopilotSuggestion $suggestion */
        $suggestion = $this->resource;
        $content = $suggestion->content;
        $categories = ServiceCategory::query()->active()->get()->keyBy('slug');
        $present = fn (mixed $slug): ?array => is_string($slug) && $categories->has($slug)
            ? [...(new ServiceCategoryResource($categories->get($slug)))->resolve($request), 'providers_search' => array_filter(['category' => $slug, 'country' => $this->country])]
            : null;

        return [
            'uuid' => $suggestion->uuid,
            'kind' => $suggestion->kind->value,
            'status' => $suggestion->status->value,
            'step' => $suggestion->step?->uuid,
            'content' => $suggestion->kind === SuggestionKind::Roadmap ? [
                'summary' => $content['summary'] ?? null,
                'steps' => array_map(fn (array $step, int $index): array => [
                    'index' => $index,
                    'title' => $step['title'],
                    'description' => $step['description'] ?? null,
                    'category' => $present($step['category'] ?? null),
                    'tasks' => $step['tasks'] ?? [],
                ], $content['steps'] ?? [], array_keys($content['steps'] ?? [])),
                'categories' => array_values(array_filter(array_map(fn (array $item): ?array => ($category = $present($item['slug'] ?? null)) === null ? null : [
                    ...$category,
                    'reason' => $item['reason'] ?? null,
                ], $content['categories'] ?? []))),
            ] : [
                'title' => $content['title'] ?? null,
                'category' => $present($content['category'] ?? null),
                'sections' => SpecificationSections::normalize(is_array($content['sections'] ?? null) ? $content['sections'] : []),
            ],
            'ai' => [
                'generated' => true,
                'provider' => $this->providerLabel,
                'model' => $suggestion->model,
                'prompt_version' => $suggestion->prompt_version,
                'disclaimer' => CopilotTexts::disclaimer(Locale::fromRequest($request)),
            ],
            'created_at' => $suggestion->created_at?->toIso8601ZuluString(),
            'applied_at' => $suggestion->applied_at?->toIso8601ZuluString(),
            'expires_at' => $suggestion->created_at?->copy()->addDays((int) config('security.retention.copilot_suggestions_days'))->toIso8601ZuluString(),
        ];
    }
}
