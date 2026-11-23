<?php

namespace App\Modules\Marketplace\Models;

use App\Models\Media;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Accounts\Actions\PublishedProviderConstraint;
use App\Modules\Marketplace\Enums\DeliveryMode;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Enums\PricingMode;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Marketplace\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Offre d'un prestataire (décisions 39 et 40) : fiche de services publique, prix indicatif TTC.
 *
 * - uuid identifie l'offre pour son auteur, slug (calculé à la première publication, stable)
 *   pour le public.
 * - Visible du public si elle est publiée, non masquée, et si le profil prestataire de son
 *   auteur est publié et non masqué (scope visible()).
 * - Seuls les champs de contenu sont assignables ; uuid, slug, auteur, catégorie, statut,
 *   version et dates sont fixés par les Actions.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $slug
 * @property int $user_id
 * @property int $service_category_id
 * @property int|null $image_media_id
 * @property string $title
 * @property string $summary
 * @property string $description
 * @property PricingMode $pricing_mode
 * @property int|null $price_cents
 * @property string $currency
 * @property DeliveryMode $delivery_mode
 * @property string $country
 * @property string|null $city
 * @property OfferStatus $status
 * @property int $version
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $unpublished_at
 * @property CarbonInterface|null $moderation_restricted_at
 * @property string|null $moderation_decision_uuid
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read ServiceCategory $category
 */
#[UseFactory(OfferFactory::class)]
#[Fillable(['title', 'summary', 'description', 'pricing_mode', 'price_cents', 'delivery_mode', 'country', 'city'])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    protected $table = 'marketplace_offers';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'EUR',
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing_mode' => PricingMode::class,
            'price_cents' => 'integer',
            'delivery_mode' => DeliveryMode::class,
            'status' => OfferStatus::class,
            'version' => 'integer',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'moderation_restricted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * Image de couverture (décision 83), fixée par MediaLibrary::replace(), jamais assignable.
     *
     * @return BelongsTo<Media, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_media_id');
    }

    /**
     * Offres publiées et non masquées (sans regarder le profil de l'auteur).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', OfferStatus::Published->value)->whereNull('moderation_restricted_at');
    }

    /**
     * Offres visibles du public : publiées, non masquées, et dont l'auteur a un profil
     * prestataire publié et non masqué (Action publique du module Accounts).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->published();
        app(PublishedProviderConstraint::class)->apply($query, 'user_id');
    }
}
