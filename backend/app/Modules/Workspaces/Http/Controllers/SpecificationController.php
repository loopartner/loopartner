<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Workspaces\Actions\ManageSpecifications;
use App\Modules\Workspaces\Http\Requests\SaveSpecificationRequest;
use App\Modules\Workspaces\Http\Resources\SpecificationResource;
use App\Modules\Workspaces\Models\Specification;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Cahiers des charges d'un workspace de l'utilisateur connecté (404 pour autrui).
 */
class SpecificationController extends Controller
{
    public function __construct(private ManageSpecifications $manage, private Workspaces $workspaces) {}

    public function index(Request $request, string $uuid): JsonResponse
    {
        $workspace = $this->own($request, $uuid);
        $specifications = Specification::query()->where('workspace_id', $workspace->id)->with(['category', 'step'])->orderByDesc('id')->get();

        return response()->json([
            'data' => $specifications->map(fn (Specification $specification): array => (new SpecificationResource($specification))->resolve($request))->values()->all(),
        ]);
    }

    public function store(SaveSpecificationRequest $request): JsonResponse
    {
        $specification = $this->manage->create($this->user($request), $request->workspace(), $request->validated());

        return (new SpecificationResource($specification->load(['category', 'step'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $uuid, string $specification): SpecificationResource
    {
        return new SpecificationResource(ManageSpecifications::find($this->own($request, $uuid), $specification)->load(['category', 'step']));
    }

    public function update(SaveSpecificationRequest $request, string $uuid, string $specification): SpecificationResource
    {
        $workspace = $request->workspace();
        $updated = $this->manage->replace($this->user($request), $workspace, ManageSpecifications::find($workspace, $specification), $request->validated());

        return new SpecificationResource($updated->load(['category', 'step']));
    }

    public function destroy(Request $request, string $uuid, string $specification): Response
    {
        $workspace = $this->own($request, $uuid);
        $this->manage->delete($this->user($request), $workspace, ManageSpecifications::find($workspace, $specification));

        return response()->noContent();
    }

    private function own(Request $request, string $uuid): Workspace
    {
        Gate::authorize('use', Workspace::class);

        return $this->workspaces->own($this->user($request), $uuid);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
