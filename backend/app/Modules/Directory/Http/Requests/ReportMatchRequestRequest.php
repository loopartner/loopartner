<?php

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Support\MatchingRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /me/match-requests/{uuid}/report : le destinataire signale une demande abusive.
 * L'autorisation passe avant la validation (404 hors participants, 403 pour l'auteur).
 */
class ReportMatchRequestRequest extends FormRequest
{
    private ?MatchRequest $matchRequest = null;

    public function authorize(): Response
    {
        return Gate::inspect('report', $this->matchRequest());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return MatchingRules::report();
    }

    public function matchRequest(): MatchRequest
    {
        if ($this->matchRequest === null) {
            $matchRequest = MatchRequest::query()->where('uuid', (string) $this->route('uuid'))->first();
            abort_if($matchRequest === null, 404, 'Demande introuvable.');
            $this->matchRequest = $matchRequest;
        }

        return $this->matchRequest;
    }
}
