<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Modules\Workspaces\Exceptions\EmailNotVerified;
use App\Modules\Workspaces\Support\CopilotTexts;
use App\Rules\ActiveServiceCategory;
use Illuminate\Auth\Access\Response;
use Illuminate\Validation\Rule;

/**
 * POST /me/workspaces/{uuid}/copilot/roadmap et …/copilot/specification.
 *
 * - notice_version : version de l'information sur l'IA lue par l'utilisateur, égale à la
 *   version en vigueur (422 sinon : le frontend la montre de nouveau) ;
 * - cahier des charges : étape (uuid d'une étape du workspace) et catégorie facultatives.
 */
class GenerateRequest extends WorkspaceRequest
{
    /**
     * Rôle et workspace (WorkspaceRequest), puis email vérifié (403 email_not_verified), avant
     * la validation.
     */
    public function authorize(): Response
    {
        $response = parent::authorize();

        if ($response->allowed() && $this->user()?->hasVerifiedEmail() !== true) {
            throw new EmailNotVerified;
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'notice_version' => ['required', 'string', Rule::in([CopilotTexts::NOTICE_VERSION])],
        ];

        if ($this->routeIs('workspaces.copilot.specification')) {
            $rules['step'] = ['sometimes', 'nullable', 'string', 'uuid'];
            $rules['category'] = ['sometimes', 'nullable', 'string', 'max:100', new ActiveServiceCategory];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['notice_version.in' => 'L\'information sur le copilote a changé : relisez-la avant de lancer une génération.'];
    }
}
