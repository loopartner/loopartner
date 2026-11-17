<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Modules\Accounts\Actions\FindPublishedProvider;
use App\Modules\Marketplace\Http\Requests\SearchOffersRequest;
use App\Modules\Marketplace\Http\Resources\PublicOfferResource;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Support\OfferAuthors;
use App\Support\Database\Like;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Offres publiques : publiées, non masquées, d'un prestataire au profil publié et non masqué.
 * Triées par date de publication décroissante, le même ordre pour tous (aucune mise en avant
 * payante, décision 18).
 */
class PublicOfferController extends Controller
{
    public function __construct(private OfferAuthors $authors) {}

    public function index(SearchOffersRequest $request): AnonymousResourceCollection
    {
        return $this->search($request, Offer::query()->visible());
    }

    /**
     * Offres d'un prestataire (GET /providers/{slug}/offers) : 404 si son profil n'est pas
     * publié ou s'il est masqué.
     */
    public function byProvider(SearchOffersRequest $request, string $slug, FindPublishedProvider $findProvider): AnonymousResourceCollection
    {
        $provider = $findProvider->handle($slug);
        abort_if($provider === null, 404, 'Prestataire introuvable.');

        return $this->search($request, Offer::query()->visible()->where('user_id', $provider['user_id']));
    }

    public function show(string $slug): PublicOfferResource
    {
        $offer = Offer::query()->visible()->with('category')->where('slug', $slug)->first();

        // abort() plutôt que firstOrFail() : le message 404 ne révèle pas le nom du modèle.
        abort_if($offer === null, 404, 'Offre introuvable.');
        $this->authors->load([$offer]);

        return new PublicOfferResource($offer);
    }

    /**
     * @param  Builder<Offer>  $query
     */
    private function search(SearchOffersRequest $request, Builder $query): AnonymousResourceCollection
    {
        if ($request->filled('category')) {
            $query->whereIn('service_category_id', ServiceCategory::idsWithDescendants($request->string('category')->toString()));
        }

        if ($request->filled('country')) {
            $query->where('country', $request->string('country')->toString());
        }

        if ($request->filled('city')) {
            Like::whereEquals($query, 'city', $request->string('city')->trim()->toString());
        }

        if ($request->filled('pricing_mode')) {
            $query->where('pricing_mode', $request->string('pricing_mode')->toString());
        }

        if ($request->filled('q')) {
            Like::whereContains($query, ['title', 'summary', 'description'], $request->string('q')->trim()->toString());
        }

        $offers = $query
            ->with('category')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $this->authors->load($offers->items());

        return PublicOfferResource::collection($offers);
    }
}
