<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\ManageTask;
use App\Modules\Compliance\Http\Requests\TaskRequest;
use App\Modules\Compliance\Http\Resources\TaskResource;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\Task;
use App\Modules\Compliance\Support\Disclaimer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Tâches d'une entreprise (libres, sur une échéance, ou étapes d'un parcours). La tâche d'une
 * autre entreprise, ou d'un autre utilisateur, donne 404.
 */
class TaskController extends Controller
{
    private const RELATIONS = ['deadline.rule', 'journey.rule'];

    /**
     * Tâches libres de l'entreprise (ni échéance ni parcours) ; les autres se lisent avec leur
     * échéance ou leur parcours.
     */
    public function index(Request $request, string $company): AnonymousResourceCollection
    {
        $model = CompanyController::find($company, 'view');
        $tasks = Task::query()->where('company_id', $model->id)->whereNull('deadline_id')->whereNull('journey_id')
            ->with(self::RELATIONS)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return TaskResource::collection($tasks)->additional(['disclaimer' => Disclaimer::forRequest($request)]);
    }

    public function store(TaskRequest $request, ManageTask $manageTask): JsonResponse
    {
        /** @var array{title: string, deadline?: string|null, due_on?: string|null, service_category?: string|null} $data */
        $data = $request->validated();
        $task = $manageTask->create($request->company(), $data);

        return (new TaskResource($task->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(TaskRequest $request, string $company, string $task, ManageTask $manageTask): TaskResource
    {
        $model = $manageTask->update($request->company(), self::find($request->company(), $task), $request->validated());

        return new TaskResource($model->load(self::RELATIONS));
    }

    public function complete(string $company, string $task, ManageTask $manageTask): TaskResource
    {
        $model = CompanyController::find($company, 'update');

        return new TaskResource($manageTask->setCompleted($model, self::find($model, $task), true)->load(self::RELATIONS));
    }

    public function reopen(string $company, string $task, ManageTask $manageTask): TaskResource
    {
        $model = CompanyController::find($company, 'update');

        return new TaskResource($manageTask->setCompleted($model, self::find($model, $task), false)->load(self::RELATIONS));
    }

    public function destroy(string $company, string $task, ManageTask $manageTask): Response
    {
        $model = CompanyController::find($company, 'update');
        $manageTask->delete($model, self::find($model, $task));

        return response()->noContent();
    }

    private static function find(Company $company, string $uuid): Task
    {
        $task = Task::query()->where('company_id', $company->id)->where('uuid', $uuid)->first();
        abort_if($task === null, 404, 'Tâche introuvable.');

        return $task;
    }
}
