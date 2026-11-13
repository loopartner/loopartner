<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use App\Modules\Compliance\Enums\DeadlineSource;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Task;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tâche à cocher. Une tâche issue d'une règle lit son titre traduit dans la règle (tâche d'une
 * échéance ou étape d'un parcours) ; une tâche personnelle renvoie le titre saisi.
 *
 * service_category : catégorie active de l'annuaire quand la tâche demande un professionnel ;
 * le frontend propose alors des prestataires avec GET /providers?category={slug}&country={pays}.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Task $task */
        $task = $this->resource;
        $locale = Locale::fromRequest($request);
        $template = self::template($task);
        $category = $task->service_category_slug === null
            ? null
            : ServiceCategory::query()->active()->where('slug', $task->service_category_slug)->first();

        return [
            'uuid' => $task->uuid,
            'source' => $task->source->value,
            'title' => $task->source === DeadlineSource::Rule
                ? (ComplianceRule::translate(is_array($template['title'] ?? null) ? $template['title'] : null, $locale) ?? $task->template_key)
                : $task->title,
            'description' => is_array($template['description'] ?? null) ? ComplianceRule::translate($template['description'], $locale) : null,
            'source_url' => is_string($template['source_url'] ?? null) ? $template['source_url'] : null,
            'service_category' => $category === null ? null : (new ServiceCategoryResource($category))->toArray($request),
            'due_on' => $task->due_on?->toDateString(),
            'completed' => $task->completed_at !== null,
            'completed_at' => $task->completed_at?->toIso8601ZuluString(),
            'deadline' => $task->deadline?->uuid,
            'journey' => $task->journey?->uuid,
        ];
    }

    /**
     * Modèle de la tâche dans sa règle (tâche d'échéance ou étape de parcours).
     *
     * @return array<string, mixed>
     */
    private static function template(Task $task): array
    {
        if ($task->source !== DeadlineSource::Rule) {
            return [];
        }

        $deadline = $task->deadline;
        $items = $deadline !== null
            ? ($deadline->rule?->payload['tasks'] ?? [])
            : ($task->journey?->rule?->payload['steps'] ?? []);

        foreach ((array) $items as $item) {
            if (is_array($item) && ($item['key'] ?? null) === $task->template_key) {
                return $item;
            }
        }

        return [];
    }
}
