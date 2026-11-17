<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\QuoteLine;
use App\Modules\Marketplace\Models\SellerProfile;

/**
 * Contenu figé d'un devis envoyé : identité de l'émetteur copiée de son identité
 * professionnelle, et empreinte SHA-256 du contenu (content_hash), imprimée sur le PDF et
 * contrôlée à l'acceptation (le client accepte exactement ce qu'il a lu).
 */
final class QuoteDocument
{
    /**
     * Identité de l'émetteur, telle qu'elle figure sur le devis (mentions obligatoires :
     * arrêté du 24 janvier 2017, Code de la consommation R111-1 et R111-2, Code de commerce
     * R123-237, Code de l'artisanat L132-1).
     *
     * @return array<string, mixed>
     */
    public static function issuer(SellerProfile $seller): array
    {
        return [
            'legal_name' => $seller->legal_name,
            'trade_name' => $seller->trade_name,
            'legal_form' => $seller->legal_form->value,
            'legal_form_label' => ['fr' => $seller->legal_form->label('fr'), 'en' => $seller->legal_form->label('en')],
            'individual' => $seller->legal_form->isIndividual(),
            'registration_number' => $seller->registration_number,
            'registry' => $seller->registry->value,
            'registry_city' => $seller->registry_city,
            'share_capital_cents' => $seller->share_capital_cents,
            'vat_number' => $seller->vat_number,
            'address_line1' => $seller->address_line1,
            'address_line2' => $seller->address_line2,
            'postal_code' => $seller->postal_code,
            'city' => $seller->city,
            'country' => $seller->country,
            'phone' => $seller->phone,
            'email' => $seller->email,
            'insurer_name' => $seller->insurer_name,
            'insurer_contact' => $seller->insurer_contact,
            'insurance_coverage' => $seller->insurance_coverage,
            'insurance_ten_year' => $seller->insurance_ten_year,
            'mediator_name' => $seller->mediator_name,
            'mediator_url' => $seller->mediator_url,
            'regulated_profession_title' => $seller->regulated_profession_title,
            'regulated_profession_state' => $seller->regulated_profession_state,
            'regulated_profession_body' => $seller->regulated_profession_body,
            'terms_url' => $seller->terms_url,
            'self_certification_version' => $seller->self_certification_version,
        ];
    }

    /**
     * Contenu engageant du devis, dans un ordre fixe.
     *
     * @return array<string, mixed>
     */
    public static function canonical(Quote $quote): array
    {
        return [
            'number' => $quote->number,
            'version' => $quote->version,
            'client_kind' => $quote->client_kind->value,
            'title' => $quote->title,
            'scope' => $quote->scope,
            'offer_title' => $quote->offer_title,
            'issuer' => $quote->issuer_snapshot?->getArrayCopy(),
            'client' => $quote->client_snapshot->getArrayCopy(),
            'currency' => $quote->currency,
            'lines' => $quote->lines->map(fn (QuoteLine $line): array => [
                'position' => $line->position,
                'line_type' => $line->line_type->value,
                'designation' => $line->designation,
                'details' => $line->details,
                'quantity_milli' => $line->quantity_milli,
                'unit' => $line->unit->value,
                'unit_price_cents' => $line->unit_price_cents,
                'vat_rate_code' => $line->vat_rate_code,
                'vat_rate_version' => $line->vat_rate_version,
                'vat_basis_points' => $line->vat_basis_points,
                'total_ht_cents' => $line->total_ht_cents,
            ])->values()->all(),
            'totals' => [$quote->total_ht_cents, $quote->total_vat_cents, $quote->total_ttc_cents],
            'vat_breakdown' => $quote->vat_breakdown,
            'valid_until' => $quote->valid_until?->toDateString(),
            'start_date' => $quote->start_date?->toDateString(),
            'duration' => $quote->duration,
            'payment_terms' => $quote->payment_terms,
            'deposit' => [$quote->deposit_cents, $quote->deposit_kind?->value],
            'quote_fee' => [$quote->quote_is_free, $quote->quote_fee_cents],
            'keep_replaced_parts' => $quote->keep_replaced_parts,
            'notes' => $quote->notes,
        ];
    }

    public static function hash(Quote $quote): string
    {
        return hash('sha256', (string) json_encode(self::canonical($quote), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
