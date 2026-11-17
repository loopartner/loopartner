<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Enums\DepositKind;
use App\Modules\Marketplace\Enums\QuoteLineType;
use App\Modules\Marketplace\Enums\QuoteUnit;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeLinksInText;
use Illuminate\Validation\Rule;

/**
 * Validation du contenu d'un brouillon de devis (représentation complète : POST et PUT).
 *
 * Le taux de TVA d'une ligne est le code d'une règle vérifiée en vigueur dans le pays du
 * prestataire : aucun taux n'est saisi. SaveQuoteDraft vérifie le code (422) après les
 * conditions d'accès (404, 403, 409). Les champs fixés par la plateforme (numéro, statut,
 * parties, totaux, empreinte, dates…) sont refusés.
 */
final class QuoteRules
{
    public const PROHIBITED = [
        'uuid', 'number', 'version', 'status', 'provider_id', 'client_id', 'project_id', 'client_kind',
        'total_ht_cents', 'total_vat_cents', 'total_ttc_cents', 'vat_breakdown', 'issuer', 'issuer_snapshot',
        'content_hash', 'valid_until', 'expires_at', 'sent_at', 'accepted_at', 'currency',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $text = fn (int $min, int $max): array => ['string', 'min:'.$min, 'max:'.$max, new NoHtml, new NoInvisibleCharacters, new SafeLinksInText];
        $quotes = (array) config('marketplace.quotes');
        $validity = (array) $quotes['validity_days'];

        return [
            'title' => ['required', ...$text(5, 160)],
            'scope' => ['required', ...$text(20, 5000)],
            'offer' => ['nullable', 'string', 'uuid'],
            'client' => ['required', 'array:name,company_name,registration_number,address,execution_place'],
            'client.name' => ['required', ...$text(2, 160)],
            'client.company_name' => ['nullable', ...$text(2, 160)],
            'client.registration_number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 .\-]{4,40}$/'],
            'client.address' => ['nullable', ...$text(5, 300)],
            // Lieu d'exécution (arrêté du 24 janvier 2017, art. 4) : une adresse, ou « à distance ».
            'client.execution_place' => ['required', ...$text(3, 300)],
            'validity_days' => ['sometimes', 'integer', 'between:'.(int) $validity['min'].','.(int) $validity['max']],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'duration' => ['nullable', ...$text(2, 200)],
            'payment_terms' => ['nullable', ...$text(2, 1000)],
            'deposit_cents' => ['nullable', 'integer', 'min:1', 'max:'.(int) $quotes['max_total_cents']],
            'deposit_kind' => ['nullable', 'required_with:deposit_cents', Rule::enum(DepositKind::class)],
            'quote_is_free' => ['sometimes', 'boolean'],
            'quote_fee_cents' => ['nullable', 'required_if:quote_is_free,false,0', 'prohibited_if:quote_is_free,true,1', 'integer', 'min:1', 'max:'.(int) $quotes['max_unit_price_cents']],
            'keep_replaced_parts' => ['nullable', 'boolean'],
            'notes' => ['nullable', ...$text(2, 3000)],
            'lines' => ['required', 'list', 'min:1', 'max:'.(int) $quotes['max_lines']],
            'lines.*' => ['array:line_type,designation,details,quantity_milli,unit,unit_price_cents,vat_rate_code'],
            'lines.*.line_type' => ['required', 'string', Rule::enum(QuoteLineType::class)],
            'lines.*.designation' => ['required', ...$text(2, 300)],
            'lines.*.details' => ['nullable', ...$text(1, 1000)],
            'lines.*.quantity_milli' => ['required', 'integer', 'min:1', 'max:'.(int) $quotes['max_quantity_milli']],
            'lines.*.unit' => ['required', 'string', Rule::enum(QuoteUnit::class)],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0', 'max:'.(int) $quotes['max_unit_price_cents']],
            'lines.*.vat_rate_code' => ['required', 'string', 'max:100'],
            ...array_fill_keys(self::PROHIBITED, ['prohibited']),
        ];
    }
}
