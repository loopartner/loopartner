<?php

namespace Database\Factories\Modules\Marketplace;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Offre de test en brouillon : création de site vitrine, à partir de 1 500 € TTC (prix
 * indicatif fictif).
 *
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'service_category_id' => ServiceCategory::factory(),
            'title' => 'Création de site vitrine',
            'summary' => 'Un site vitrine responsive de cinq pages, livré en trois semaines avec sa mise en ligne.',
            'description' => 'Maquette, intégration, formulaire de contact, référencement de base et formation à la mise à jour.',
            'pricing_mode' => 'from',
            'price_cents' => 150_000,
            'currency' => 'EUR',
            'delivery_mode' => 'remote',
            'country' => 'FR',
            'city' => 'Lyon',
            'status' => OfferStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::Published,
            'slug' => 'creation-de-site-vitrine-'.Str::lower(Str::random(6)),
            'published_at' => now(),
        ]);
    }
}
