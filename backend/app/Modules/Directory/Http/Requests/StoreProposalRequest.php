<?php

namespace App\Modules\Directory\Http\Requests;

use App\Models\User;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\EarlyAccess;
use App\Modules\Directory\Support\MatchingRules;
use App\Modules\Directory\Support\ProjectRules;
use App\Rules\CurrencyCode;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /projects/{uuid}/proposals : un prestataire propose ses services sur un projet publié.
 *
 * L'autorisation passe avant la validation : projet non visible (brouillon, clos, expiré,
 * inconnu) 404, puis ProjectPolicy::propose (profil prestataire publié, blocage) 403.
 * Seuls message, price_amount et price_currency sont lus ; tout autre champ est ignoré.
 */
class StoreProposalRequest extends FormRequest
{
    private ?Project $project = null;

    public function authorize(): Response
    {
        return Gate::inspect('propose', $this->project());
    }

    protected function prepareForValidation(): void
    {
        $currency = $this->input('price_currency');

        if (is_string($currency)) {
            $this->merge(['price_currency' => strtoupper(trim($currency))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => MatchingRules::requestMessage(),
            'price_amount' => ['nullable', 'integer', 'min:1', 'max:'.ProjectRules::BUDGET_MAX],
            'price_currency' => ['nullable', 'required_with:price_amount', 'string', 'size:3', new CurrencyCode],
        ];
    }

    /**
     * Projet publié et non expiré visé par l'URL (404 sinon, sans révéler son existence).
     */
    public function project(): Project
    {
        if ($this->project === null) {
            /** @var User $user */
            $user = $this->user();
            $project = app(EarlyAccess::class)->constrain(Project::query()->browsable(), $user)
                ->where('uuid', (string) $this->route('uuid'))
                ->first();
            abort_if($project === null, 404, 'Projet introuvable.');
            $this->project = $project;
        }

        return $this->project;
    }
}
