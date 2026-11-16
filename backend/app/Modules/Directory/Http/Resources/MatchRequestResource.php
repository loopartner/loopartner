<?php

namespace App\Modules\Directory\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Support\ViewerContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Demande de mise en relation, vue par l'un de ses deux participants.
 *
 * Confidentialité :
 * - aucun identifiant interne (ni id, ni user_id, ni project_id) : seulement des uuid ;
 * - l'interlocuteur ("counterpart") est présenté par ViewerContext : résumé public pour un
 *   prestataire, type de compte et pays pour un porteur de projet, dont le nom n'apparaît
 *   qu'une fois la demande acceptée ; jamais d'email ni de téléphone ;
 * - le projet est présenté comme dans la liste des projets publiés, sans son propriétaire.
 *
 * Les relations author.roles, recipient.roles, project.category et conversation doivent être
 * chargées.
 *
 * @mixin MatchRequest
 */
class MatchRequestResource extends JsonResource
{
    public function __construct(MatchRequest $resource, private readonly ViewerContext $context)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $this->context->viewer;
        $isAuthor = $this->resource->isAuthor($viewer);
        $other = $isAuthor ? $this->recipient : $this->author;
        $project = $this->project;
        $conversation = $this->conversation;

        return [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'role' => $isAuthor ? 'author' : 'recipient',
            // Demande masquée par la modération : son texte n'est plus montré (null ; une
            // demande n'est jamais vide autrement).
            'message' => $this->moderation_restricted_at === null ? $this->message : null,
            'price' => $this->price_amount === null ? null : [
                'amount' => $this->price_amount,
                'currency' => $this->price_currency,
            ],
            'project' => $project === null ? null : [
                'uuid' => $project->uuid,
                'title' => $project->title,
                'description' => $project->description,
                'category' => new ServiceCategoryResource($project->category),
                'budget_min' => $project->budget_min,
                'budget_max' => $project->budget_max,
                'currency' => $project->currency,
                'country' => $project->country,
                'city' => $project->city,
                'remote_ok' => $project->remote_ok,
            ],
            'counterpart' => $this->context->counterpart($other, revealName: $this->status === MatchRequestStatus::Accepted),
            'conversation' => $conversation === null ? null : ['uuid' => $conversation->uuid],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'expires_at' => $this->status === MatchRequestStatus::Pending ? $this->expires_at?->toIso8601ZuluString() : null,
            'responded_at' => $this->responded_at?->toIso8601ZuluString(),
        ];
    }
}
