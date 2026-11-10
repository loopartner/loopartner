<?php

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\ProjectRules;
use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Rules\CurrencyCode;
use App\Support\Pagination;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /projects : filtres de la liste des projets publiés, réservée aux prestataires.
 *
 * - remote_ok accepte true/false, 1/0 ;
 * - budget_min / budget_max : fourchette recherchée par le prestataire (voir le contrôleur) ;
 * - currency : devise des projets. Elle vaut EUR par défaut dès qu'un filtre de budget est
 *   présent, car comparer des montants de devises différentes n'aurait pas de sens.
 */
class BrowseProjectsRequest extends FormRequest
{
    /**
     * La réponse de la Policy est renvoyée telle quelle, avec son message (profil à publier).
     */
    public function authorize(): Response
    {
        return Gate::inspect('browse', Project::class);
    }

    protected function prepareForValidation(): void
    {
        $input = ProjectRules::normalize($this->query());

        // Les paramètres d'URL sont des chaînes : "true" et "false" sont acceptés comme booléens.
        if (isset($input['remote_ok']) && is_string($input['remote_ok'])) {
            $input['remote_ok'] = match (strtolower($input['remote_ok'])) {
                'true' => '1',
                'false' => '0',
                default => $input['remote_ok'],
            };
        }

        $this->replace($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Un paramètre vide (?country=) devient null et n'applique aucun filtre.
        return [
            'category' => ['nullable', 'string', 'max:100', new ActiveServiceCategory],
            'country' => ['nullable', 'string', 'size:2', new CountryCode],
            'remote_ok' => ['nullable', 'boolean'],
            'budget_min' => ['nullable', 'integer', 'min:0', 'max:'.ProjectRules::BUDGET_MAX],
            'budget_max' => ['nullable', 'integer', 'min:0', 'max:'.ProjectRules::BUDGET_MAX],
            'currency' => ['nullable', 'string', 'size:3', new CurrencyCode],
            ...Pagination::rules(),
        ];
    }
}
