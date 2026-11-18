<?php

namespace Database\Factories\Modules\Workspaces;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Workspace de test : projet de site vitrine, actif, en France.
 *
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'title' => 'Créer le site de ma boutique',
            'description' => 'Je souhaite un site vitrine simple pour présenter ma boutique de céramique et prendre des commandes.',
            'country' => 'FR',
            'budget_min' => 1500,
            'budget_max' => 4000,
            'currency' => 'EUR',
            'remote_ok' => true,
            'desired_start_date' => null,
            'status' => WorkspaceStatus::Active,
            'activated_at' => now(),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => WorkspaceStatus::Archived, 'archived_at' => now()]);
    }
}
