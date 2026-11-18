<?php

namespace App\Modules\Workspaces\Http\Requests;

/**
 * POST /me/workspaces/{uuid}/linked-project : {"project": "<uuid d'un de ses projets>"}.
 */
class LinkProjectRequest extends WorkspaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project' => ['required', 'string', 'uuid'],
        ];
    }
}
