<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workspaces\Http\Requests\DirectoryDraftRequest;
use App\Modules\Workspaces\Http\Requests\LinkProjectRequest;
use App\Modules\Workspaces\Http\Resources\WorkspaceResource;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\LinkedProjects;
use App\Modules\Workspaces\Support\WorkspacePresenter;
use App\Modules\Workspaces\Support\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Projet Directory relié à un workspace (décision 69) : relier un de ses projets, détacher,
 * ou créer un brouillon de projet à partir du workspace. Workspace modifiable exigé.
 */
class LinkedProjectController extends Controller
{
    public function __construct(private LinkedProjects $linkedProjects, private Workspaces $workspaces, private WorkspacePresenter $presenter) {}

    public function store(LinkProjectRequest $request): WorkspaceResource
    {
        $user = $this->user($request);
        $workspace = $request->workspace();
        $this->workspaces->assertWritable($user, $workspace);

        return $this->presenter->resource($this->linkedProjects->link($user, $workspace, (string) $request->validated('project')));
    }

    public function destroy(Request $request, string $uuid): WorkspaceResource
    {
        Gate::authorize('use', Workspace::class);
        $user = $this->user($request);
        $workspace = $this->workspaces->own($user, $uuid);
        $this->workspaces->assertWritable($user, $workspace);

        return $this->presenter->resource($this->linkedProjects->unlink($workspace));
    }

    public function draft(DirectoryDraftRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $workspace = $request->workspace();
        $this->workspaces->assertWritable($user, $workspace);

        $project = $this->linkedProjects->createDraft($user, $workspace, $request->validated());

        return response()->json(['data' => ['project' => $project]], 201);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
