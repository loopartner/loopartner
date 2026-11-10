<?php

namespace App\Modules\Directory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Directory\Actions\CloseProject;
use App\Modules\Directory\Actions\CreateProject;
use App\Modules\Directory\Actions\DeleteProject;
use App\Modules\Directory\Actions\PublishProject;
use App\Modules\Directory\Actions\UpdateProject;
use App\Modules\Directory\Http\Requests\ListOwnProjectsRequest;
use App\Modules\Directory\Http\Requests\StoreProjectRequest;
use App\Modules\Directory\Http\Requests\UpdateProjectRequest;
use App\Modules\Directory\Http\Resources\ProjectResource;
use App\Modules\Directory\Models\Project;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Projets de l'utilisateur connecté (particuliers et entrepreneurs).
 * Le projet d'un autre utilisateur donne 404 (ProjectPolicy).
 */
class OwnProjectController extends Controller
{
    public function index(ListOwnProjectsRequest $request): AnonymousResourceCollection
    {
        $projects = Project::query()
            ->where('user_id', $this->user($request)->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->with('category')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request, CreateProject $createProject): JsonResponse
    {
        /** @var array{title: string, description: string, category: string, budget_min?: int|string|null, budget_max?: int|string|null, currency?: string, country: string, city?: string|null, remote_ok?: bool|int|string, desired_start_date?: string|null} $data */
        $data = $request->validated();

        return (new ProjectResource($createProject->handle($this->user($request), $data)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $uuid): ProjectResource
    {
        $project = $this->findProject($uuid);
        Gate::authorize('view', $project);

        return new ProjectResource($project->load('category'));
    }

    public function update(UpdateProjectRequest $request, UpdateProject $updateProject): ProjectResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return new ProjectResource($updateProject->handle($request->project(), $data));
    }

    public function destroy(string $uuid, DeleteProject $deleteProject): Response
    {
        $project = $this->findProject($uuid);
        Gate::authorize('delete', $project);

        $deleteProject->handle($project);

        return response()->noContent();
    }

    public function publish(Request $request, string $uuid, PublishProject $publishProject): ProjectResource
    {
        $project = $this->findProject($uuid);
        Gate::authorize('publish', $project);

        return new ProjectResource($publishProject->handle($this->user($request), $project));
    }

    public function close(string $uuid, CloseProject $closeProject): ProjectResource
    {
        $project = $this->findProject($uuid);
        Gate::authorize('close', $project);

        return new ProjectResource($closeProject->handle($project));
    }

    /**
     * abort() plutôt que firstOrFail() : le message 404 ne révèle pas le nom du modèle.
     */
    private function findProject(string $uuid): Project
    {
        $project = Project::query()->where('uuid', $uuid)->first();
        abort_if($project === null, 404, 'Projet introuvable.');

        return $project;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
