<?php

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /me/match-requests : demandes reçues (box=received) ou envoyées (box=sent) par
 * l'utilisateur connecté, filtrables par statut et par type. Un filtre vide est ignoré.
 */
class ListMatchRequestsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'box' => ['required', 'string', Rule::in(['received', 'sent'])],
            'status' => ['nullable', 'string', Rule::enum(MatchRequestStatus::class)],
            'type' => ['nullable', 'string', Rule::enum(MatchRequestType::class)],
            ...Pagination::rules(),
        ];
    }
}
