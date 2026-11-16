<?php

namespace App\Modules\Moderation\Http\Requests;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Motif d'une décision de l'admin (levée d'une restriction) : texte brut, 10 à 1000 caractères.
 */
class ReasonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = (array) config('moderation.limits.reason');

        return [
            'reason' => ['required', 'string', 'min:'.$limits['min'], 'max:'.$limits['max'], new NoHtml, new NoInvisibleCharacters],
        ];
    }
}
