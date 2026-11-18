<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workspaces\Actions\ManageWorkspace;
use App\Modules\Workspaces\Http\Requests\SaveWorkspaceRequest;
use App\Modules\Workspaces\Http\Resources\WorkspaceResource;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\WorkspacePresenter;
use App\Modules\Workspaces\Support\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Workspaces de l'utilisateur connecté (particulier ou entrepreneur). Le workspace d'un autre
 * utilisateur donne 404, admins compris.
 */
class WorkspaceController extends Controller
{
    public function __construct(private Workspaces $workspaces, private ManageWorkspace $manage, private WorkspacePresenter $presenter) {}

    /**
     * Liste : actifs puis archivés, du plus récemment activé au plus ancien (résumés, sans le
     * contenu).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('use', Workspace::class);
        $user = $this->user($request);

        $workspaces = Workspace::query()
            ->where('user_id', $user->id)
            ->orderBy('status')
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $workspaces->map(fn (Workspace $workspace): array => $this->presenter->resource($workspace, detailed: false)->resolve($request))->values()->all(),
            'meta' => $this->presenter->quota($user),
        ]);
    }

    public function store(SaveWorkspaceRequest $request): JsonResponse
    {
        $workspace = $this->manage->create($this->user($request), $request->validated());

        return $this->presenter->resource($workspace)->response()->setStatusCode(201);
    }

    public function show(Request $request, string $uuid): WorkspaceResource
    {
        Gate::authorize('use', Workspace::class);

        return $this->presenter->resource($this->workspaces->own($this->user($request), $uuid));
    }

    public function update(SaveWorkspaceRequest $request): WorkspaceResource
    {
        $workspace = $this->manage->update($this->user($request), $request->workspace(), $request->validated());

        return $this->presenter->resource($workspace);
    }

    public function destroy(Request $request, string $uuid): Response
    {
        Gate::authorize('use', Workspace::class);
        $this->manage->delete($this->user($request), $this->workspaces->own($this->user($request), $uuid));

        return response()->noContent();
    }

    public function archive(Request $request, string $uuid): WorkspaceResource
    {
        return $this->lifecycle($request, $uuid, 'archive');
    }

    public function unarchive(Request $request, string $uuid): WorkspaceResource
    {
        return $this->lifecycle($request, $uuid, 'unarchive');
    }

    public function activate(Request $request, string $uuid): WorkspaceResource
    {
        return $this->lifecycle($request, $uuid, 'activate');
    }

    /**
     * @param  'archive'|'unarchive'|'activate'  $operation
     */
    private function lifecycle(Request $request, string $uuid, string $operation): WorkspaceResource
    {
        Gate::authorize('use', Workspace::class);
        $user = $this->user($request);
        $workspace = $this->manage->{$operation}($user, $this->workspaces->own($user, $uuid));

        return $this->presenter->resource($workspace);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
