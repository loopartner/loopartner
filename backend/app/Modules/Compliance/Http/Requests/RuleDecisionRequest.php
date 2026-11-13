<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Vérification (POST …/verify) ou retrait (POST …/retire) d'une règle.
 *
 * - version : version lue par l'admin (409 si la règle a changé depuis).
 * - verification_note (vérification) : obligatoire, au moins
 *   compliance.verification_note_min_length caractères ; elle doit dire qui a vérifié (nom du
 *   cabinet ou du professionnel), quand, et sur quel document ou quelle source.
 * - reason (retrait) : motif obligatoire, écrit dans le journal d'audit.
 */
class RuleDecisionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $verify = $this->route()?->getActionMethod() === 'verify';
        $text = ['required', 'string', 'max:2000', new NoHtml, new NoInvisibleCharacters];

        return [
            'version' => ['required', 'integer', 'min:1'],
            'verification_note' => $verify ? [...$text, 'min:'.(int) config('compliance.verification_note_min_length')] : ['prohibited'],
            'reason' => $verify ? ['prohibited'] : [...$text, 'min:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'verification_note.required' => 'La note de vérification est obligatoire : indiquez qui a vérifié la règle (cabinet ou professionnel), quand, et sur quel document ou quelle source.',
            'verification_note.min' => 'La note de vérification doit faire au moins :min caractères : indiquez qui a vérifié la règle (cabinet ou professionnel), quand, et sur quel document ou quelle source.',
        ];
    }
}
