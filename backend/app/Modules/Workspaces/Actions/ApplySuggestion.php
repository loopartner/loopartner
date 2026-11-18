<?php

namespace App\Modules\Workspaces\Actions;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Workspaces\Enums\ItemSource;
use App\Modules\Workspaces\Enums\SuggestionKind;
use App\Modules\Workspaces\Enums\SuggestionStatus;
use App\Modules\Workspaces\Models\CopilotSuggestion;
use App\Modules\Workspaces\Models\Specification;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\Workspaces;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Application d'une suggestion du copilote par l'utilisateur (décision 66) :
 * - feuille de route : les étapes choisies (toutes par défaut) et leurs tâches sont ajoutées
 *   à la fin du workspace, avec source "copilot" et ai_generated_at, dans les limites du
 *   workspace (tout ou rien, 409) ;
 * - cahier des charges : un cahier est créé (source "copilot").
 * Une suggestion ne s'applique qu'une fois (409) ; elle peut aussi être écartée.
 */
class ApplySuggestion
{
    public function __construct(private Workspaces $workspaces) {}

    /**
     * @param  list<int>|null  $stepIndexes  étapes retenues (index de la suggestion), null : toutes
     */
    public function apply(User $user, Workspace $workspace, CopilotSuggestion $suggestion, ?array $stepIndexes = null): CopilotSuggestion
    {
        $this->workspaces->assertWritable($user, $workspace);

        DB::transaction(function () use ($workspace, $suggestion, $stepIndexes): void {
            $workspace = Workspaces::lock($workspace);
            $suggestion = $this->pending($suggestion);

            if ($suggestion->kind === SuggestionKind::Roadmap) {
                $this->applyRoadmap($workspace, $suggestion, $stepIndexes);
            } else {
                $this->applySpecification($workspace, $suggestion);
            }

            $suggestion->forceFill(['status' => SuggestionStatus::Applied, 'applied_at' => now()])->save();
        });

        SecurityLog::record('copilot.suggestion_applied', ['user_id' => $user->id, 'workspace' => $workspace->uuid, 'suggestion' => $suggestion->uuid]);

        return $suggestion->refresh();
    }

    public function dismiss(CopilotSuggestion $suggestion): CopilotSuggestion
    {
        return DB::transaction(function () use ($suggestion): CopilotSuggestion {
            $suggestion = $this->pending($suggestion);
            $suggestion->forceFill(['status' => SuggestionStatus::Dismissed])->save();

            return $suggestion;
        });
    }

    private function pending(CopilotSuggestion $suggestion): CopilotSuggestion
    {
        $locked = CopilotSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== SuggestionStatus::Pending) {
            throw new ConflictHttpException('Cette suggestion a déjà été appliquée ou écartée.');
        }

        return $locked;
    }

    /**
     * @param  list<int>|null  $stepIndexes
     */
    private function applyRoadmap(Workspace $workspace, CopilotSuggestion $suggestion, ?array $stepIndexes): void
    {
        /** @var list<array{title: string, description: string|null, category: string|null, tasks: list<string>}> $steps */
        $steps = $suggestion->content['steps'] ?? [];

        if ($stepIndexes !== null) {
            foreach ($stepIndexes as $index) {
                if (! array_key_exists($index, $steps)) {
                    throw ValidationException::withMessages(['steps' => 'Étape inconnue dans cette suggestion.']);
                }
            }

            $steps = array_values(array_intersect_key($steps, array_flip($stepIndexes)));
        }

        $existingSteps = Step::query()->where('workspace_id', $workspace->id)->count();
        $existingTasks = Task::query()->where('workspace_id', $workspace->id)->count();
        $newTasks = array_sum(array_map(fn (array $step): int => count($step['tasks']), $steps));

        if ($existingSteps + count($steps) > (int) config('workspaces.limits.steps') || $existingTasks + $newTasks > (int) config('workspaces.limits.tasks')) {
            throw new ConflictHttpException('Ce workspace n\'a plus la place pour ces étapes : supprimez-en ou choisissez-en moins.');
        }

        $position = (int) Step::query()->where('workspace_id', $workspace->id)->max('position') + ($existingSteps > 0 ? 1 : 0);

        foreach ($steps as $data) {
            $step = new Step;
            $step->forceFill([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'service_category_id' => $this->categoryId($data['category']),
                'source' => ItemSource::Copilot,
                'ai_generated_at' => $suggestion->created_at,
                'position' => $position++,
            ])->save();

            foreach (array_slice($data['tasks'], 0, (int) config('workspaces.limits.tasks_per_step')) as $taskPosition => $title) {
                (new Task)->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'step_id' => $step->id,
                    'title' => $title,
                    'source' => ItemSource::Copilot,
                    'ai_generated_at' => $suggestion->created_at,
                    'position' => $taskPosition,
                ])->save();
            }
        }
    }

    private function applySpecification(Workspace $workspace, CopilotSuggestion $suggestion): void
    {
        $max = (int) config('workspaces.limits.specifications');

        if (Specification::query()->where('workspace_id', $workspace->id)->count() >= $max) {
            throw new ConflictHttpException(sprintf('Ce workspace a déjà %d cahiers des charges : c\'est le maximum.', $max));
        }

        $content = $suggestion->content;

        (new Specification)->forceFill([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'step_id' => $suggestion->step_id,
            'service_category_id' => $this->categoryId(is_string($content['category'] ?? null) ? $content['category'] : null),
            'title' => (string) ($content['title'] ?? ''),
            'sections' => is_array($content['sections'] ?? null) ? $content['sections'] : [],
            'source' => ItemSource::Copilot,
            'ai_generated_at' => $suggestion->created_at,
        ])->save();
    }

    private function categoryId(?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }

        $id = ServiceCategory::query()->active()->where('slug', $slug)->value('id');

        return is_int($id) ? $id : null;
    }
}
