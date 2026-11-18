<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workspaces\Actions\ManageContent;
use App\Modules\Workspaces\Http\Requests\ReorderRequest;
use App\Modules\Workspaces\Http\Requests\SaveGoalRequest;
use App\Modules\Workspaces\Http\Requests\SaveStepRequest;
use App\Modules\Workspaces\Http\Requests\SaveTaskRequest;
use App\Modules\Workspaces\Http\Resources\GoalResource;
use App\Modules\Workspaces\Http\Resources\StepResource;
use App\Modules\Workspaces\Http\Resources\TaskResource;
use App\Modules\Workspaces\Models\Goal;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Objectifs, étapes et tâches d'un workspace de l'utilisateur connecté. Workspace ou élément
 * d'un autre utilisateur : 404.
 */
class ContentController extends Controller
{
    public function __construct(private ManageContent $manage, private Workspaces $workspaces) {}

    public function storeGoal(SaveGoalRequest $request): JsonResponse
    {
        $goal = $this->manage->createGoal($this->user($request), $request->workspace(), $request->validated());

        return (new GoalResource($goal))->response()->setStatusCode(201);
    }

    public function updateGoal(SaveGoalRequest $request, string $uuid, string $goal): GoalResource
    {
        $workspace = $request->workspace();

        return new GoalResource($this->manage->updateGoal($this->user($request), $workspace, ManageContent::find($workspace, Goal::class, $goal), $request->validated()));
    }

    public function storeStep(SaveStepRequest $request): JsonResponse
    {
        $step = $this->manage->createStep($this->user($request), $request->workspace(), $request->validated());

        return $this->stepResource($request->workspace(), $step)->response()->setStatusCode(201);
    }

    public function updateStep(SaveStepRequest $request, string $uuid, string $step): StepResource
    {
        $workspace = $request->workspace();
        $updated = $this->manage->updateStep($this->user($request), $workspace, ManageContent::find($workspace, Step::class, $step), $request->validated());

        return $this->stepResource($workspace, $updated);
    }

    public function storeTask(SaveTaskRequest $request, string $uuid, string $step): JsonResponse
    {
        $workspace = $request->workspace();
        $task = $this->manage->createTask($this->user($request), $workspace, ManageContent::find($workspace, Step::class, $step), $request->validated());

        return (new TaskResource($task))->response()->setStatusCode(201);
    }

    public function updateTask(SaveTaskRequest $request, string $uuid, string $task): TaskResource
    {
        $workspace = $request->workspace();

        return new TaskResource($this->manage->updateTask($this->user($request), $workspace, ManageContent::find($workspace, Task::class, $task), $request->validated()));
    }

    public function destroyGoal(Request $request, string $uuid, string $goal): Response
    {
        return $this->destroy($request, $uuid, Goal::class, $goal);
    }

    public function destroyStep(Request $request, string $uuid, string $step): Response
    {
        return $this->destroy($request, $uuid, Step::class, $step);
    }

    public function destroyTask(Request $request, string $uuid, string $task): Response
    {
        return $this->destroy($request, $uuid, Task::class, $task);
    }

    public function reorderGoals(ReorderRequest $request): Response
    {
        return $this->reorder($request, Goal::class);
    }

    public function reorderSteps(ReorderRequest $request): Response
    {
        return $this->reorder($request, Step::class);
    }

    public function reorderTasks(ReorderRequest $request, string $uuid, string $step): Response
    {
        return $this->reorder($request, Task::class, ManageContent::find($request->workspace(), Step::class, $step));
    }

    /**
     * @param  class-string<Goal|Step|Task>  $model
     */
    private function destroy(Request $request, string $uuid, string $model, string $itemUuid): Response
    {
        Gate::authorize('use', Workspace::class);
        $user = $this->user($request);
        $workspace = $this->workspaces->own($user, $uuid);
        $this->manage->delete($user, $workspace, ManageContent::find($workspace, $model, $itemUuid));

        return response()->noContent();
    }

    /**
     * @param  class-string<Goal|Step|Task>  $model
     */
    private function reorder(ReorderRequest $request, string $model, ?Step $step = null): Response
    {
        /** @var list<string> $order */
        $order = $request->validated('order');
        $this->manage->reorder($this->user($request), $request->workspace(), $model, $order, $step);

        return response()->noContent();
    }

    private function stepResource(Workspace $workspace, Step $step): StepResource
    {
        return new StepResource($step->load(['category', 'tasks']), $workspace->country);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
