<?php

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Marketplace\Models\SellerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Identité professionnelle telle que son titulaire la voit (/me/seller-profile). Ni id ni
 * user_id ; ce qui manque pour publier une offre ou envoyer un devis est annoncé.
 *
 * @mixin SellerProfile
 */
class SellerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'legal_form' => $this->legal_form->value,
            'registration_number' => $this->registration_number,
            'registry' => $this->registry->value,
            'registry_city' => $this->registry_city,
            'share_capital_cents' => $this->share_capital_cents,
            'vat_number' => $this->vat_number,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
            'insurer_name' => $this->insurer_name,
            'insurer_contact' => $this->insurer_contact,
            'insurance_coverage' => $this->insurance_coverage,
            'insurance_ten_year' => $this->insurance_ten_year,
            'mediator_name' => $this->mediator_name,
            'mediator_url' => $this->mediator_url,
            'regulated_profession_title' => $this->regulated_profession_title,
            'regulated_profession_state' => $this->regulated_profession_state,
            'regulated_profession_body' => $this->regulated_profession_body,
            'terms_url' => $this->terms_url,
            'quote_footer' => $this->quote_footer,
            'self_certification' => [
                'accepted_at' => $this->self_certified_at?->toIso8601ZuluString(),
                'version' => $this->self_certification_version,
                'current_version' => (string) config('marketplace.self_certification.version'),
            ],
            // Vérification de l'immatriculation par l'API publique de l'État : lot 6.
            'registration_verification' => [
                'status' => $this->registration_verification_status,
                'verified_at' => $this->registration_verified_at?->toIso8601ZuluString(),
            ],
            'missing_for_offers' => $this->missingForOffers(),
            'missing_for_quotes' => $this->missingForQuotes(),
            'missing_for_consumer_quotes' => $this->missingForConsumerQuotes(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
