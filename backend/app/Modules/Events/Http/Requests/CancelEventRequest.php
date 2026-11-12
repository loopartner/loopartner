<?php

namespace App\Modules\Events\Http\Requests;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeLinksInText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/events/{uuid}/cancel : motif facultatif, lisible par les inscrits sur la page de
 * l'événement (jamais dans l'email). L'autorisation (organisateur) est vérifiée par le contrôleur.
 */
class CancelEventRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000', new NoHtml, new NoInvisibleCharacters, new SafeLinksInText],
        ];
    }
}
