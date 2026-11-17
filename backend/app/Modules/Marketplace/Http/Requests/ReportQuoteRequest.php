<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Models\Quote;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /me/quotes/{uuid}/report : catégorie de signalement (liste fermée de
 * config/moderation.php) et commentaire facultatif. Réservé au client du devis (403 pour le
 * prestataire, 404 pour tout autre utilisateur), vérifié avant la validation.
 */
class ReportQuoteRequest extends FormRequest
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
            'category' => ['required', 'string', Rule::in(array_keys((array) config('moderation.categories')))],
            'comment' => ['nullable', 'string', 'max:1000', new NoHtml, new NoInvisibleCharacters],
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
