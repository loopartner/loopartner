<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Modules\Workspaces\Support\ContentRules;

/**
 * POST /me/workspaces/{uuid}/goals et PATCH /me/workspaces/{uuid}/goals/{goal}.
 */
class SaveGoalRequest extends WorkspaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ContentRules::goal($this->partial());
    }
}
