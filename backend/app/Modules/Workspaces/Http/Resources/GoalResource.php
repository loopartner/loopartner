<?php

namespace App\Modules\Workspaces\Http\Resources;

use App\Modules\Workspaces\Models\Goal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Objectif vu par le propriétaire du workspace.
 *
 * @mixin Goal
 */
class GoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Goal $goal */
        $goal = $this->resource;

        return [
            'uuid' => $goal->uuid,
            'title' => $goal->title,
            'description' => $goal->description,
            'target_date' => $goal->target_date?->toDateString(),
            'achieved' => $goal->achieved_at !== null,
            'achieved_at' => $goal->achieved_at?->toIso8601ZuluString(),
            'position' => $goal->position,
        ];
    }
}
