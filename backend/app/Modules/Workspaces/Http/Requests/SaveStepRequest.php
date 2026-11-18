<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Modules\Workspaces\Support\ContentRules;

/**
 * POST /me/workspaces/{uuid}/steps et PATCH /me/workspaces/{uuid}/steps/{step}.
 */
class SaveStepRequest extends WorkspaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ContentRules::step($this->partial());
    }
}
