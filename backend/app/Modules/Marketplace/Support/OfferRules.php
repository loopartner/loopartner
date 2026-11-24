<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Marketplace\Enums\DeliveryMode;
use App\Modules\Marketplace\Enums\PricingMode;
use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeLinksInText;
use App\Support\Media\MediaPurpose;
use App\Support\Media\Rules\AttachableMedia;
use Illuminate\Validation\Rule;

/**
 * Validation du contenu d'une offre (création et modification partielle).
 *
 * Le prix est indicatif et saisi TTC (décision 40) : obligatoire sauf pour une offre « sur
 * devis », où il est interdit. Les champs fixés par la plateforme (statut, slug, auteur,
 * version, dates, devise) sont refusés, comme image_media_id (l'image passe par « image »).
 */
final class OfferRules
{
    /**
     * Champs fixés par la plateforme : refusés s'ils sont envoyés (422).
     */
    public const PROHIBITED = ['uuid', 'slug', 'user_id', 'status', 'version', 'currency', 'published_at', 'unpublished_at', 'moderation_restricted_at', 'moderation_decision_uuid', 'image_media_id'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function rules(bool $partial, array $input, User $user, ?PricingMode $currentMode = null, ?int $currentImageId = null): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $text = fn (int $min, int $max): array => ['string', 'min:'.$min, 'max:'.$max, new NoHtml, new NoInvisibleCharacters, new SafeLinksInText];
        $mode = PricingMode::tryFrom((string) ($input['pricing_mode'] ?? '')) ?? $currentMode;
        $priceRule = $mode === PricingMode::OnQuote ? 'prohibited' : ($partial && ! array_key_exists('pricing_mode', $input) ? 'sometimes' : 'required');

        return [
            'title' => [$required, ...$text(10, 160)],
            'summary' => [$required, ...$text(30, 300)],
            'description' => [$required, ...$text(30, 5000)],
            'category' => [$required, 'string', 'max:100', new ActiveServiceCategory],
            'pricing_mode' => [$required, 'string', Rule::enum(PricingMode::class)],
            'price_cents' => [$priceRule, 'integer', 'min:1', 'max:'.(int) config('marketplace.quotes.max_unit_price_cents')],
            'delivery_mode' => [$required, 'string', Rule::enum(DeliveryMode::class)],
            'country' => [$required, 'string', 'size:2', new CountryCode],
            'city' => ['sometimes', 'nullable', 'string', 'max:100', new NoHtml, new NoInvisibleCharacters],
            // Image de couverture facultative (décision 83) : uuid d'un média offer_image de
            // l'utilisateur, ou null pour la retirer ; absente, elle reste inchangée.
            'image' => ['sometimes', 'nullable', 'string', new AttachableMedia($user, MediaPurpose::OfferImage, $currentImageId)],
            ...array_fill_keys(self::PROHIBITED, ['prohibited']),
        ];
    }

    /**
     * Normalise l'entrée : pays en majuscules.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        if (is_string($input['country'] ?? null)) {
            $input['country'] = strtoupper(trim($input['country']));
        }

        return $input;
    }
}
