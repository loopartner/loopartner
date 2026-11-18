<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Modules\Workspaces\Support\ContentRules;

/**
 * POST /me/workspaces/{uuid}/steps/{step}/tasks et PATCH /me/workspaces/{uuid}/tasks/{task}
 * (déplacement vers une autre étape par "step").
 */
class SaveTaskRequest extends WorkspaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ContentRules::task($this->partial());
    }
}
