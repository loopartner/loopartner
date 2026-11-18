<?php

namespace App\Modules\Workspaces\Actions;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Workspaces\Models\Goal;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\Workspaces;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Objectifs, étapes et tâches d'un workspace (décision 63).
 *
 * - Workspace modifiable exigé : archivé 409, verrouillé 403 (Workspaces::assertWritable).
 * - Limites (config/workspaces.php, limits) comptées sous le verrou de la ligne du workspace :
 *   objectifs, étapes, tâches par étape et par workspace (409).
 * - Nouvel élément : placé en dernier. L'ordre se change par reorder() (liste complète).
 * - completed / achieved : date de réalisation posée ou retirée.
 * - Une étape ou une tâche issue du copilote garde ai_generated_at même modifiée.
 */
class ManageContent
{
    public function __construct(private Workspaces $workspaces) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createGoal(User $user, Workspace $workspace, array $data): Goal
    {
        return $this->inLockedWorkspace($user, $workspace, function (Workspace $workspace) use ($data): Goal {
            $this->assertBelow(Goal::query()->where('workspace_id', $workspace->id)->count(), 'goals', 'objectifs');

            $goal = new Goal($data);
            $goal->forceFill([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'achieved_at' => ($data['achieved'] ?? false) === true ? now() : null,
                'position' => $this->nextPosition(Goal::query()->where('workspace_id', $workspace->id)->max('position')),
            ])->save();

            return $goal;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGoal(User $user, Workspace $workspace, Goal $goal, array $data): Goal
    {
        $this->workspaces->assertWritable($user, $workspace);

        $goal->fill($data);
        if (array_key_exists('achieved', $data)) {
            $goal->achieved_at = $data['achieved'] === true ? ($goal->achieved_at ?? now()) : null;
        }
        $goal->save();

        return $goal;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createStep(User $user, Workspace $workspace, array $data): Step
    {
        return $this->inLockedWorkspace($user, $workspace, function (Workspace $workspace) use ($data): Step {
            $this->assertBelow(Step::query()->where('workspace_id', $workspace->id)->count(), 'steps', 'étapes');

            $step = new Step($data);
            $step->forceFill([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'service_category_id' => self::categoryId($data['category'] ?? null),
                'completed_at' => ($data['completed'] ?? false) === true ? now() : null,
                'position' => $this->nextPosition(Step::query()->where('workspace_id', $workspace->id)->max('position')),
            ])->save();

            return $step;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateStep(User $user, Workspace $workspace, Step $step, array $data): Step
    {
        $this->workspaces->assertWritable($user, $workspace);

        $step->fill($data);
        if (array_key_exists('category', $data)) {
            $step->service_category_id = self::categoryId($data['category']);
        }
        if (array_key_exists('completed', $data)) {
            $step->completed_at = $data['completed'] === true ? ($step->completed_at ?? now()) : null;
        }
        $step->save();

        return $step;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTask(User $user, Workspace $workspace, Step $step, array $data): Task
    {
        return $this->inLockedWorkspace($user, $workspace, function (Workspace $workspace) use ($step, $data): Task {
            $this->assertTaskRoom($workspace, $step);

            $task = new Task($data);
            $task->forceFill([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'step_id' => $step->id,
                'completed_at' => ($data['completed'] ?? false) === true ? now() : null,
                'position' => $this->nextPosition(Task::query()->where('step_id', $step->id)->max('position')),
            ])->save();

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTask(User $user, Workspace $workspace, Task $task, array $data): Task
    {
        return $this->inLockedWorkspace($user, $workspace, function (Workspace $workspace) use ($task, $data): Task {
            if (isset($data['step']) && is_string($data['step'])) {
                $target = Step::query()->where('workspace_id', $workspace->id)->where('uuid', $data['step'])->first();

                if ($target === null) {
                    throw ValidationException::withMessages(['step' => 'Cette étape n\'appartient pas à ce workspace.']);
                }

                if ($target->id !== $task->step_id) {
                    $this->assertTaskRoom($workspace, $target, countWorkspace: false);
                    $task->step_id = $target->id;
                    $task->position = $this->nextPosition(Task::query()->where('step_id', $target->id)->max('position'));
                }
            }

            unset($data['step']);
            $task->fill($data);
            if (array_key_exists('completed', $data)) {
                $task->completed_at = $data['completed'] === true ? ($task->completed_at ?? now()) : null;
            }
            $task->save();

            return $task;
        });
    }

    /**
     * Supprime un objectif, une étape (avec ses tâches) ou une tâche.
     */
    public function delete(User $user, Workspace $workspace, Goal|Step|Task $item): void
    {
        $this->workspaces->assertWritable($user, $workspace);

        $item->delete();
    }

    /**
     * Nouvel ordre d'une liste : tous ses éléments, chacun une fois (422 sinon).
     *
     * @param  class-string<Goal|Step|Task>  $model
     * @param  list<string>  $order  uuids dans le nouvel ordre
     */
    public function reorder(User $user, Workspace $workspace, string $model, array $order, ?Step $step = null): void
    {
        $this->inLockedWorkspace($user, $workspace, function (Workspace $workspace) use ($model, $order, $step): void {
            $query = $model::query()->where('workspace_id', $workspace->id);

            if ($step !== null) {
                $query->where('step_id', $step->id);
            }

            /** @var array<string, int> $ids */
            $ids = $query->pluck('id', 'uuid')->all();
            $expected = array_keys($ids);
            $given = $order;
            sort($expected);
            sort($given);

            if ($expected !== $given) {
                throw ValidationException::withMessages(['order' => 'La liste doit contenir chaque élément une fois, et seulement eux.']);
            }

            foreach ($order as $position => $uuid) {
                $model::query()->whereKey($ids[$uuid])->update(['position' => $position]);
            }
        });
    }

    /**
     * Élément du workspace par son uuid, sinon 404.
     *
     * @template TModel of Goal|Step|Task
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    public static function find(Workspace $workspace, string $model, string $uuid): Model
    {
        $item = $model::query()->where('workspace_id', $workspace->id)->where('uuid', $uuid)->first();

        if ($item === null) {
            throw new NotFoundHttpException('Élément introuvable.');
        }

        return $item;
    }

    /**
     * @template TResult
     *
     * @param  callable(Workspace): TResult  $callback
     * @return TResult
     */
    private function inLockedWorkspace(User $user, Workspace $workspace, callable $callback): mixed
    {
        $this->workspaces->assertWritable($user, $workspace);

        return DB::transaction(fn (): mixed => $callback(Workspaces::lock($workspace)));
    }

    private function assertTaskRoom(Workspace $workspace, Step $step, bool $countWorkspace = true): void
    {
        $this->assertBelow(Task::query()->where('step_id', $step->id)->count(), 'tasks_per_step', 'tâches dans cette étape');

        if ($countWorkspace) {
            $this->assertBelow(Task::query()->where('workspace_id', $workspace->id)->count(), 'tasks', 'tâches dans ce workspace');
        }
    }

    private function assertBelow(int $count, string $limit, string $label): void
    {
        $max = (int) config('workspaces.limits.'.$limit);

        if ($count >= $max) {
            throw new ConflictHttpException(sprintf('Vous avez déjà %d %s : c\'est le maximum.', $max, $label));
        }
    }

    private function nextPosition(mixed $max): int
    {
        return $max === null ? 0 : (int) $max + 1;
    }

    private static function categoryId(mixed $slug): ?int
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $id = ServiceCategory::query()->active()->where('slug', $slug)->value('id');

        return is_int($id) ? $id : null;
    }
}
