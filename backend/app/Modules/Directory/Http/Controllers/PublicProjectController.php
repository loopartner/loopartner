<?php

namespace App\Modules\Directory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Modules\Directory\Http\Requests\BrowsePublicProjectsRequest;
use App\Modules\Directory\Http\Resources\PublicProjectResource;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\EarlyAccess;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Vitrine publique des projets (décision 71) : tout visiteur, avec ou sans compte.
 *
 * - Seuls les projets consultables (publiés, non expirés, non masqués par la modération).
 * - Accès anticipé respecté : un projet n'apparaît qu'après la fenêtre réservée aux prestataires
 *   Premium (décision 44), comme pour un prestataire gratuit.
 * - Contenu limité (PublicProjectResource) ; limiteur par IP `public-projects`.
 */
class PublicProjectController extends Controller
{
    public function index(BrowsePublicProjectsRequest $request): AnonymousResourceCollection
    {
        $query = $this->visible()->with('category');

        if ($request->filled('category')) {
            $query->whereIn('service_category_id', ServiceCategory::idsWithDescendants($request->string('category')->toString()));
        }

        if ($request->filled('country')) {
            $query->where('country', $request->string('country')->toString());
        }

        $projects = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return PublicProjectResource::collection($projects);
    }

    public function show(string $uuid): PublicProjectResource
    {
        $project = $this->visible()->with('category')->where('uuid', $uuid)->first();

        abort_if($project === null, 404, 'Projet introuvable.');

        return new PublicProjectResource($project);
    }

    /**
     * @return Builder<Project>
     */
    private function visible(): Builder
    {
        $query = Project::query()->browsable();

        if (EarlyAccess::hours() > 0) {
            $query->where('published_at', '<=', now()->subHours(EarlyAccess::hours()));
        }

        return $query;
    }
}
