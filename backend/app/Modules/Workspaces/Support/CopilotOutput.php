<?php

namespace App\Modules\Workspaces\Support;

use App\Models\ServiceCategory;
use App\Modules\Workspaces\Enums\SuggestionKind;
use App\Support\Ai\Exceptions\AiInvalidResponse;

/**
 * Nettoyage de la réponse de l'IA avant de la garder comme suggestion (décision 34) : seules
 * les clés attendues sont reprises, chaque texte passe par GeneratedText (HTML écarté, liens
 * retirés, longueur bornée), les catégories sont filtrées sur les catégories actives, les
 * listes sont tronquées aux limites de config/workspaces.php (copilot). Réponse vide après
 * nettoyage : AiInvalidResponse (502, non décomptée).
 */
final class CopilotOutput
{
    /**
     * @param  array<string, mixed>  $data  réponse décodée de l'IA (non fiable)
     * @return array<string, mixed>
     */
    public static function clean(SuggestionKind $kind, array $data): array
    {
        return match ($kind) {
            SuggestionKind::Roadmap => self::roadmap($data),
            SuggestionKind::Specification => self::specification($data),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{summary: string|null, steps: list<array{title: string, description: string|null, category: string|null, tasks: list<string>}>, categories: list<array{slug: string, reason: string|null}>}
     */
    private static function roadmap(array $data): array
    {
        $limits = config('workspaces.copilot');
        $title = ContentRules::length('title');
        $active = ServiceCategory::query()->active()->pluck('slug')->all();
        $category = fn (mixed $slug): ?string => is_string($slug) && in_array($slug, $active, true) ? $slug : null;

        $steps = [];

        foreach (self::list($data['steps'] ?? null) as $step) {
            $stepTitle = GeneratedText::clean($step['title'] ?? null, $title);

            if ($stepTitle === null) {
                continue;
            }

            if (count($steps) >= (int) $limits['max_steps']) {
                break;
            }

            $tasks = [];

            foreach (array_slice(is_array($step['tasks'] ?? null) ? array_values($step['tasks']) : [], 0, (int) $limits['max_tasks_per_step']) as $task) {
                $taskTitle = GeneratedText::clean($task, $title);

                if ($taskTitle !== null) {
                    $tasks[] = $taskTitle;
                }
            }

            $steps[] = [
                'title' => $stepTitle,
                'description' => GeneratedText::clean($step['description'] ?? null, ContentRules::length('note')),
                'category' => $category($step['category'] ?? null),
                'tasks' => $tasks,
            ];
        }

        if ($steps === []) {
            throw new AiInvalidResponse('empty_roadmap');
        }

        $categories = [];

        foreach (self::list($data['categories'] ?? null) as $item) {
            $slug = $category($item['slug'] ?? null);

            if ($slug !== null && ! in_array($slug, array_column($categories, 'slug'), true) && count($categories) < (int) $limits['max_categories']) {
                $categories[] = ['slug' => $slug, 'reason' => GeneratedText::clean($item['reason'] ?? null, 300)];
            }
        }

        return [
            'summary' => GeneratedText::clean($data['summary'] ?? null, 600),
            'steps' => $steps,
            'categories' => $categories,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, sections: array<string, string|null>}
     */
    private static function specification(array $data): array
    {
        $title = GeneratedText::clean($data['title'] ?? null, ContentRules::length('title'));
        $raw = is_array($data['sections'] ?? null) ? $data['sections'] : [];
        $sections = [];

        foreach (SpecificationSections::KEYS as $key) {
            $sections[$key] = GeneratedText::clean($raw[$key] ?? null, ContentRules::length('section'));
        }

        if ($title === null || array_filter($sections) === []) {
            throw new AiInvalidResponse('empty_specification');
        }

        return ['title' => $title, 'sections' => $sections];
    }

    /**
     * Liste d'objets (tableaux associatifs) ; tout autre élément est ignoré.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
