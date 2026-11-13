<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\Journey;
use App\Modules\Compliance\Support\Disclaimer;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Parcours suivi par une entreprise, avec ses étapes (tâches) et sa progression.
 *
 * @mixin Journey
 */
class JourneyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Journey $journey */
        $journey = $this->resource;
        $tasks = $journey->tasks;

        return [
            'uuid' => $journey->uuid,
            'code' => $journey->rule_code,
            'title' => $journey->rule?->titleIn(Locale::fromRequest($request)),
            'started_at' => $journey->started_at->toIso8601ZuluString(),
            'completed_at' => $journey->completed_at?->toIso8601ZuluString(),
            'progress' => ['done' => $tasks->whereNotNull('completed_at')->count(), 'total' => $tasks->count()],
            'steps' => TaskResource::collection($tasks)->toArray($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['disclaimer' => Disclaimer::forRequest($request)];
    }
}
