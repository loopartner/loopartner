<?php

namespace App\Modules\Directory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Directory\Actions\RecordProjectConsultation;
use App\Modules\Directory\Exceptions\PremiumFeatureRequired;
use App\Modules\Directory\Http\Requests\BrowseProjectsRequest;
use App\Modules\Directory\Http\Resources\PublishedProjectResource;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\EarlyAccess;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * Projets publiés et non expirés, consultés par les prestataires connectés avec un email
 * vérifié. Brouillons, projets clos et projets expirés sont invisibles (404 pour le détail).
 */
class ProjectBrowseController extends Controller
{
    public function index(BrowseProjectsRequest $request, RecordProjectConsultation $recordConsultation, EarlyAccess $earlyAccess): AnonymousResourceCollection
    {
        $query = $earlyAccess->constrain(Project::query()->browsable(), $this->user($request))->with(['category', 'user.roles']);

        if ($request->filled('category')) {
            $query->whereIn('service_category_id', ServiceCategory::idsWithDescendants($request->string('category')->toString()));
        }

        if ($request->filled('country')) {
            $query->where('country', $request->string('country')->toString());
        }

        if ($request->filled('remote_ok')) {
            $query->where('remote_ok', $request->boolean('remote_ok'));
        }

        $hasBudgetFilter = $request->filled('budget_min') || $request->filled('budget_max');

        if ($request->filled('currency') || $hasBudgetFilter) {
            $query->where('currency', $request->filled('currency') ? $request->string('currency')->toString() : Project::DEFAULT_CURRENCY);
        }

        // Fourchettes qui se recouvrent. Un projet sans aucun budget est exclu (COALESCE donne null).
        // Un projet dont le budget peut atteindre budget_min :
        if ($request->filled('budget_min')) {
            $query->whereRaw('COALESCE(budget_max, budget_min) >= ?', [$request->integer('budget_min')]);
        }

        // Un projet dont le budget peut descendre jusqu'à budget_max :
        if ($request->filled('budget_max')) {
            $query->whereRaw('COALESCE(budget_min, budget_max) <= ?', [$request->integer('budget_max')]);
        }

        $projects = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $recordConsultation->handle($request, $this->user($request), 'projects.browse', [
            'page' => $projects->currentPage(),
            'per_page' => $projects->perPage(),
            'filters' => array_filter(
                Arr::except($request->validated(), ['page', 'per_page']),
                fn (mixed $value): bool => $value !== null,
            ),
        ]);

        return PublishedProjectResource::collection($projects);
    }

    public function show(Request $request, string $uuid, RecordProjectConsultation $recordConsultation, EarlyAccess $earlyAccess): PublishedProjectResource
    {
        $access = Gate::inspect('browse', Project::class);
        PremiumFeatureRequired::throwIfDenied($access);
        $access->authorize();

        // Projet encore dans sa fenêtre d'accès anticipé : 404 pour un prestataire sans ce droit.
        $project = $earlyAccess->constrain(Project::query()->browsable(), $this->user($request))
            ->with(['category', 'user.roles'])
            ->where('uuid', $uuid)
            ->first();

        // Brouillon, clos, expiré ou inconnu : même réponse, sans révéler le nom du modèle.
        abort_if($project === null, 404, 'Projet introuvable.');

        $recordConsultation->handle($request, $this->user($request), 'projects.view', ['project' => $project->uuid]);

        return new PublishedProjectResource($project);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
