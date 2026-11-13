<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;

/**
 * Échéance personnelle : création (POST …/deadlines) ou modification (PATCH …/deadlines/{d}).
 *
 * - title : 1 à 160 caractères, texte brut ; due_on : d'un an en arrière à cinq ans ;
 *   notes : 2000 caractères au plus. Titre et notes sont chiffrés en base.
 * - Champs fixés par la plateforme (statut, source, règle…) : refusés (422). L'état change par
 *   POST …/complete, …/skip, …/reopen.
 */
class DeadlineRequest extends CompanyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $text = ['string', new NoHtml, new NoInvisibleCharacters];

        return [
            'title' => [$required, ...$text, 'min:1', 'max:160'],
            'due_on' => [$required, 'date_format:Y-m-d', 'after_or_equal:'.now()->subYear()->toDateString(), 'before_or_equal:'.now()->addYears(5)->toDateString()],
            'notes' => ['sometimes', 'nullable', ...$text, 'max:2000'],
            ...collect(['uuid', 'id', 'status', 'source', 'rule_code', 'rule_id', 'occurrence_key', 'company_id', 'completed_at', 'obsolete_at'])
                ->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])
                ->all(),
        ];
    }
}
