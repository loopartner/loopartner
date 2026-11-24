<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Modules\Accounts\Http\Requests\SearchProvidersRequest;
use App\Modules\Accounts\Http\Resources\PublicProviderProfileResource;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Support\Database\Like;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Pages publiques des prestataires : seuls les profils publiés sont visibles.
 */
class PublicProviderController extends Controller
{
    /**
     * Recherche publique (GET /providers), triée par date de publication décroissante.
     * La recherche texte "q" est un simple LIKE (voir App\Support\Database\Like).
     */
    public function index(SearchProvidersRequest $request): AnonymousResourceCollection
    {
        $query = ProviderProfile::query()->published()->with(['logo', 'banner', 'categories']);

        if ($request->filled('category')) {
            $categoryIds = ServiceCategory::idsWithDescendants($request->string('category')->toString());

            $query->whereHas('categories', fn (Builder $categories) => $categories->whereIn('service_categories.id', $categoryIds));
        }

        if ($request->filled('country')) {
            $query->where('country', $request->string('country')->toString());
        }

        if ($request->filled('city')) {
            Like::whereEquals($query, 'city', $request->string('city')->trim()->toString());
        }

        if ($request->filled('q')) {
            Like::whereContains($query, ['display_name', 'headline', 'bio'], $request->string('q')->trim()->toString());
        }

        $profiles = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return PublicProviderProfileResource::collection($profiles);
    }

    public function show(string $slug): PublicProviderProfileResource
    {
        $profile = ProviderProfile::query()->published()->with(['logo', 'banner', 'categories'])->where('slug', $slug)->first();

        // abort() plutôt que firstOrFail() : le message 404 ne révèle pas le nom du modèle.
        abort_if($profile === null, 404, 'Prestataire introuvable.');

        return new PublicProviderProfileResource($profile);
    }
}
