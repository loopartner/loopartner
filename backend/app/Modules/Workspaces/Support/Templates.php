<?php

namespace App\Modules\Workspaces\Support;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Workspaces\Enums\ItemSource;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\Locale;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Modèles de projet (Premium, décision 63) : fichiers JSON de database/data/workspaces/templates
 * (code, titre et description traduits, étapes avec catégorie facultative et tâches), écrits
 * par l'équipe. Aucune démarche juridique, fiscale ou comptable détaillée : ils renvoient vers
 * une catégorie de professionnels. Un modèle est copié à la création du workspace (étapes et
 * tâches d'origine "template"), puis le workspace évolue librement.
 *
 * @phpstan-type Translated array{fr: string, en: string}
 * @phpstan-type TemplateStep array{title: Translated, description: Translated|null, category: string|null, tasks: list<Translated>}
 * @phpstan-type Template array{code: string, title: Translated, description: Translated, steps: list<TemplateStep>}
 */
final class Templates
{
    public function __construct(private Plan $plan) {}

    public static function directory(): string
    {
        return database_path('data/workspaces/templates');
    }

    /**
     * @return array<string, Template>
     */
    public function all(): array
    {
        $templates = [];

        foreach (glob(self::directory().'/*.json') ?: [] as $file) {
            $template = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);

            if (! is_array($template) || ! is_string($template['code'] ?? null) || ! is_array($template['steps'] ?? null)) {
                throw new RuntimeException('Modèle de projet invalide : '.basename($file));
            }

            /** @var Template $template */
            $templates[$template['code']] = $template;
        }

        ksort($templates);

        return $templates;
    }

    /**
     * Modèle demandé à la création, après contrôle du droit Premium (403) et de son existence
     * (422 sur "template").
     *
     * @return Template
     */
    public function forCreation(User $user, string $code): array
    {
        if (! $this->plan->allows($user, 'workspaces.templates')) {
            $this->plan->requirePremium($user, 'workspace_templates');
        }

        $template = $this->all()[$code] ?? null;

        if ($template === null) {
            throw ValidationException::withMessages(['template' => 'Ce modèle de projet n\'existe pas.']);
        }

        return $template;
    }

    /**
     * Copie les étapes et les tâches du modèle dans le workspace, dans la langue donnée. Une
     * catégorie inactive est ignorée.
     *
     * @param  Template  $template
     */
    public function apply(Workspace $workspace, array $template, string $locale): void
    {
        $locale = in_array($locale, Locale::SUPPORTED, true) ? $locale : 'fr';

        foreach ($template['steps'] as $position => $data) {
            $step = new Step;
            $step->forceFill([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'title' => $data['title'][$locale],
                'description' => $data['description'][$locale] ?? null,
                'service_category_id' => $data['category'] === null ? null : ServiceCategory::query()->active()->where('slug', $data['category'])->value('id'),
                'source' => ItemSource::Template,
                'position' => $position,
            ])->save();

            foreach ($data['tasks'] as $taskPosition => $title) {
                (new Task)->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'step_id' => $step->id,
                    'title' => $title[$locale],
                    'source' => ItemSource::Template,
                    'position' => $taskPosition,
                ])->save();
            }
        }
    }

    /**
     * Liste présentée aux utilisateurs (GET /workspace-templates).
     *
     * @return list<array<string, mixed>>
     */
    public function present(User $user, string $locale): array
    {
        $available = $this->plan->allows($user, 'workspaces.templates');

        return array_values(array_map(fn (array $template): array => [
            'code' => $template['code'],
            'title' => $template['title'][$locale] ?? $template['title']['fr'],
            'description' => $template['description'][$locale] ?? $template['description']['fr'],
            'steps' => array_map(fn (array $step): string => $step['title'][$locale] ?? $step['title']['fr'], $template['steps']),
            'tasks_count' => array_sum(array_map(fn (array $step): int => count($step['tasks']), $template['steps'])),
            'premium' => true,
            'available' => $available,
        ], $this->all()));
    }
}
