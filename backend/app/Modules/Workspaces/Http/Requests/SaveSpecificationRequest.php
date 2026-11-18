<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Modules\Workspaces\Support\ContentRules;

/**
 * POST /me/workspaces/{uuid}/specifications et PUT …/specifications/{specification}
 * (représentation complète).
 */
class SaveSpecificationRequest extends WorkspaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ContentRules::specification();
    }
}
