<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Models\Quote;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /me/quotes/{uuid}/accept : le client confirme le devis qu'il a lu.
 *
 * - content_hash : empreinte reçue avec le devis (409 si le contenu a changé) ;
 * - accept : case « j'accepte ce devis » (obligatoire) ;
 * - express_execution : demande expresse d'exécution pendant le délai de rétractation
 *   (consommateur seulement) ;
 * - certifications : codes des taux dont le client certifie les conditions.
 *
 * L'autorisation passe avant la validation : 404 pour tout autre utilisateur.
 */
class AcceptQuoteRequest extends FormRequest
{
    private ?Quote $quote = null;

    public function authorize(): Response
    {
        return Gate::inspect('respond', $this->quote());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content_hash' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'accept' => ['required', 'accepted'],
            'express_execution' => ['sometimes', 'boolean'],
            'certifications' => ['sometimes', 'list', 'max:20'],
            'certifications.*' => ['string', 'max:100', 'distinct'],
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
