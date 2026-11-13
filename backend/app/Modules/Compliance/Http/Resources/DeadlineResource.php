<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Support\Disclaimer;
use App\Modules\Compliance\Support\RuleCatalog;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Échéance vue par le propriétaire de l'entreprise. Une échéance issue d'une règle présente le
 * titre et le résumé traduits de la règle, et sa source officielle (lien et date de dernière
 * vérification) ; une échéance personnelle, le titre saisi.
 *
 * - overdue : à faire et dépassée (jour de l'entreprise, fuseau de son pays).
 * - obsolete : faite ou ignorée, mais sa règle ne s'applique plus (règle retirée, profil changé).
 *
 * @mixin Deadline
 */
class DeadlineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Deadline $deadline */
        $deadline = $this->resource;
        $locale = Locale::fromRequest($request);
        $rule = $deadline->isFromRule() ? $deadline->rule : null;
        $company = $deadline->company;
        $today = CarbonImmutable::now(RuleCatalog::timezone($company->country))->toDateString();

        return [
            'uuid' => $deadline->uuid,
            'company' => ['uuid' => $company->uuid, 'name' => $company->name],
            'source' => $deadline->source->value,
            'title' => $rule !== null ? $rule->titleIn($locale) : $deadline->title,
            'summary' => $rule?->summaryIn($locale),
            'due_on' => $deadline->due_on->toDateString(),
            'status' => $deadline->status->value,
            'overdue' => $deadline->status === DeadlineStatus::Todo && $deadline->due_on->toDateString() < $today,
            'obsolete' => $deadline->obsolete_at !== null,
            'completed_at' => $deadline->completed_at?->toIso8601ZuluString(),
            'notes' => $deadline->notes,
            'rule' => $rule === null ? null : [
                'code' => $rule->code,
                'source' => ['url' => $rule->source_url, 'label' => $rule->source_label],
                'verified_at' => $rule->last_verified_at?->toIso8601ZuluString(),
            ],
            'tasks' => $this->whenLoaded('tasks', fn () => TaskResource::collection($deadline->tasks)->toArray($request)),
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
