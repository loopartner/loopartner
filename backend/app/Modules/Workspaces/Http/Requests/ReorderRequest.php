<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Modules\Workspaces\Support\ContentRules;

/**
 * PUT …/goals/order, …/steps/order et …/steps/{step}/tasks/order : {"order": [uuids]}.
 */
class ReorderRequest extends WorkspaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ContentRules::order();
    }
}
