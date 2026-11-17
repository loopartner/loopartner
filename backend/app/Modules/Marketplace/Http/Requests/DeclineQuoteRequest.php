<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Enums\DeclineReason;
use App\Modules\Marketplace\Models\Quote;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeLinksInText;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /me/quotes/{uuid}/decline : motif facultatif (liste fermée) et commentaire facultatif.
 * L'autorisation passe avant la validation : 404 pour tout autre utilisateur.
 */
class DeclineQuoteRequest extends FormRequest
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
            'reason' => ['nullable', 'string', Rule::enum(DeclineReason::class)],
            'comment' => ['nullable', 'string', 'max:1000', new NoHtml, new NoInvisibleCharacters, new SafeLinksInText],
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
