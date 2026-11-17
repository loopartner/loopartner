<?php

namespace App\Modules\Marketplace\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Modules\Marketplace\Models\Offer;
use App\Support\Moderation\Restriction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Offre telle que son auteur la voit (/me/offers). Ni id ni user_id.
 *
 * @mixin Offer
 */
class OwnOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'description' => $this->description,
            'category' => new ServiceCategoryResource($this->whenLoaded('category')),
            'pricing_mode' => $this->pricing_mode->value,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'delivery_mode' => $this->delivery_mode->value,
            'country' => $this->country,
            'city' => $this->city,
            'status' => $this->status->value,
            'version' => $this->version,
            // Masquée par la modération : invisible du public jusqu'à la levée.
            'moderation' => Restriction::view($this->resource),
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'unpublished_at' => $this->unpublished_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
