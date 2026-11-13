<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Rules\ActiveServiceCategory;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;

/**
 * Tâche personnelle : création (POST …/tasks) ou modification (PATCH …/tasks/{t}).
 *
 * - title : 1 à 200 caractères, texte brut, chiffré en base ;
 * - deadline (création) : uuid d'une échéance de la même entreprise, sinon tâche libre ;
 * - service_category : slug d'une catégorie active de l'annuaire (professionnel à consulter) ;
 * - due_on : facultatif.
 */
class TaskRequest extends CompanyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $patch = $this->isMethod('PATCH');

        return [
            'title' => [$patch ? 'sometimes' : 'required', 'string', 'min:1', 'max:200', new NoHtml, new NoInvisibleCharacters],
            'deadline' => $patch ? ['prohibited'] : ['sometimes', 'nullable', 'uuid'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.now()->subYear()->toDateString(), 'before_or_equal:'.now()->addYears(5)->toDateString()],
            'service_category' => ['sometimes', 'nullable', 'string', 'max:100', new ActiveServiceCategory],
            ...collect(['uuid', 'id', 'source', 'template_key', 'journey', 'journey_id', 'deadline_id', 'company_id', 'completed_at', 'position'])
                ->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])
                ->all(),
        ];
    }
}
