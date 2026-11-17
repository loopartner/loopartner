<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /me/quotes : devis envoyés (box=sent, prestataire) ou reçus (box=received, porteur de
 * projet), filtre facultatif par statut.
 */
class ListQuotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'box' => ['required', 'string', Rule::in(['sent', 'received'])],
            'status' => ['nullable', 'string', Rule::enum(QuoteStatus::class)],
            ...Pagination::rules(),
        ];
    }
}
