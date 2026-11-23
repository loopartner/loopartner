<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Marketplace\Actions\DeleteOffer;
use App\Modules\Marketplace\Actions\PublishOffer;
use App\Modules\Marketplace\Actions\SaveOffer;
use App\Modules\Marketplace\Actions\UnpublishOffer;
use App\Modules\Marketplace\Http\Requests\ListOwnOffersRequest;
use App\Modules\Marketplace\Http\Requests\StoreOfferRequest;
use App\Modules\Marketplace\Http\Requests\UpdateOfferRequest;
use App\Modules\Marketplace\Http\Resources\OwnOfferResource;
use App\Modules\Marketplace\Models\Offer;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Offres du prestataire connecté. L'offre d'un autre utilisateur donne 404 (OfferPolicy).
 */
class OwnOfferController extends Controller
{
    public function index(ListOwnOffersRequest $request): AnonymousResourceCollection
    {
        $offers = Offer::query()
            ->where('user_id', $this->user($request)->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->with(['category', 'image'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return OwnOfferResource::collection($offers);
    }

    public function store(StoreOfferRequest $request, SaveOffer $saveOffer): JsonResponse
    {
        $offer = $saveOffer->create($this->user($request), $request->validated());

        return (new OwnOfferResource($offer->load(['category', 'image'])))->response()->setStatusCode(201);
    }

    public function show(string $uuid): OwnOfferResource
    {
        $offer = $this->findOffer($uuid);
        Gate::authorize('view', $offer);

        return new OwnOfferResource($offer->load(['category', 'image']));
    }

    public function update(UpdateOfferRequest $request, SaveOffer $saveOffer): OwnOfferResource
    {
        return new OwnOfferResource($saveOffer->update($request->offer(), $request->validated())->load(['category', 'image']));
    }

    public function destroy(string $uuid, DeleteOffer $deleteOffer): Response
    {
        $offer = $this->findOffer($uuid);
        Gate::authorize('delete', $offer);

        $deleteOffer->handle($offer);

        return response()->noContent();
    }

    public function publish(Request $request, string $uuid, PublishOffer $publishOffer): OwnOfferResource
    {
        $offer = $this->findOffer($uuid);
        Gate::authorize('publish', $offer);

        return new OwnOfferResource($publishOffer->handle($this->user($request), $offer)->load(['category', 'image']));
    }

    public function unpublish(string $uuid, UnpublishOffer $unpublishOffer): OwnOfferResource
    {
        $offer = $this->findOffer($uuid);
        Gate::authorize('unpublish', $offer);

        return new OwnOfferResource($unpublishOffer->handle($offer)->load(['category', 'image']));
    }

    /**
     * abort() plutôt que firstOrFail() : le message 404 ne révèle pas le nom du modèle.
     */
    private function findOffer(string $uuid): Offer
    {
        $offer = Offer::query()->where('uuid', $uuid)->first();
        abort_if($offer === null, 404, 'Offre introuvable.');

        return $offer;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
