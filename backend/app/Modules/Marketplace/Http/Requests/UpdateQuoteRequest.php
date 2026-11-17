<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Support\QuoteRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /me/quotes/{uuid} : remplace un brouillon (représentation complète, lignes comprises).
 * L'autorisation passe avant la validation : le devis d'un autre utilisateur donne 404 quelles
 * que soient les données.
 */
class UpdateQuoteRequest extends FormRequest
{
    private ?Quote $quote = null;

    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->quote());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'match_request' => ['prohibited'],
            ...QuoteRules::rules(),
        ];
    }

    public function quote(): Quote
    {
        if ($this->quote === null) {
            $quote = Quote::query()->where('uuid', (string) $this->route('uuid'))->first();
            abort_if($quote === null, 404, 'Devis introuvable.');
            $this->quote = $quote;
        }

        return $this->quote;
    }
}
