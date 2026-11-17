<?php

namespace App\Modules\Marketplace\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Support\OfferAuthors;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Offre telle que le public la voit (GET /offers, GET /offers/{slug}). Ni uuid, ni id, ni
 * user_id : l'offre est désignée par son slug, son auteur par son profil public et sa qualité
 * déclarée (décision 38).
 *
 * Le prix est indicatif et saisi TTC par le prestataire (décision 40) : le frontend l'affiche
 * avec la mention « prix indicatif, seul le devis engage ».
 *
 * @mixin Offer
 */
class PublicOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $author = app(OfferAuthors::class)->for($this->resource);

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'description' => $this->description,
            'category' => new ServiceCategoryResource($this->whenLoaded('category')),
            'pricing' => [
                'mode' => $this->pricing_mode->value,
                'price_cents' => $this->price_cents,
                'currency' => $this->currency,
                'tax_basis' => 'ttc',
                'indicative' => true,
            ],
            'delivery_mode' => $this->delivery_mode->value,
            'country' => $this->country,
            'city' => $this->city,
            'provider' => $author['provider'],
            'seller' => $author['seller'],
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
