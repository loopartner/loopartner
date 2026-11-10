<?php

namespace Database\Factories\Modules\Directory;

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Demande de contact en attente par défaut. active_key est calculée à la création, comme le
 * font les Actions, pour que les contraintes d'unicité s'appliquent aussi dans les tests.
 *
 * @extends Factory<MatchRequest>
 */
class MatchRequestFactory extends Factory
{
    protected $model = MatchRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'type' => MatchRequestType::Contact,
            'author_id' => User::factory(),
            'recipient_id' => User::factory(),
            'project_id' => null,
            'message' => 'Bonjour, je souhaite échanger avec vous au sujet de mon projet.',
            'price_amount' => null,
            'price_currency' => null,
            'status' => MatchRequestStatus::Pending,
            'expires_at' => now()->addDays(MatchRequest::EXPIRATION_DAYS),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (MatchRequest $request): void {
            if (in_array($request->status, MatchRequestStatus::active(), true) && $request->author_id !== null) {
                $target = $request->type === MatchRequestType::Proposal ? $request->project_id : $request->recipient_id;
                $request->active_key = $target === null ? null : MatchRequest::activeKey($request->type, $request->author_id, $target);
            }
        });
    }

    /**
     * Proposition d'un prestataire sur le projet donné (destinataire : son propriétaire).
     */
    public function proposalOn(Project $project): static
    {
        return $this->state(fn (): array => [
            'type' => MatchRequestType::Proposal,
            'project_id' => $project->id,
            'recipient_id' => $project->user_id,
            'price_amount' => 2000,
            'price_currency' => 'EUR',
        ]);
    }

    public function status(MatchRequestStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
