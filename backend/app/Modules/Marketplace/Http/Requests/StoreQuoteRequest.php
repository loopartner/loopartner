<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Support\QuoteRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/quotes : nouveau brouillon de devis dans une demande de mise en relation acceptée
 * (match_request : uuid de la demande).
 */
class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Quote::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'match_request' => ['required', 'string', 'uuid'],
            ...QuoteRules::rules(),
        ];
    }
}
