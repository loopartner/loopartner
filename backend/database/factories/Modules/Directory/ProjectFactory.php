<?php

namespace Database\Factories\Modules\Directory;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'service_category_id' => ServiceCategory::factory(),
            'title' => fake()->sentence(5),
            'description' => fake()->paragraphs(2, true),
            'budget_min' => null,
            'budget_max' => null,
            'currency' => 'EUR',
            'country' => 'FR',
            'city' => fake()->city(),
            'remote_ok' => false,
            'desired_start_date' => null,
            'status' => ProjectStatus::Draft,
            'published_at' => null,
            'expires_at' => null,
            'closed_at' => null,
        ];
    }

    /**
     * Brouillon (état par défaut, explicite pour la lisibilité des tests).
     */
    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => ProjectStatus::Draft]);
    }

    /**
     * Publié maintenant (ou à la date donnée), pour PUBLICATION_DAYS jours.
     */
    public function published(?\DateTimeInterface $at = null): static
    {
        return $this->state(function () use ($at): array {
            $publishedAt = now()->setTimestamp(($at ?? now())->getTimestamp());

            return [
                'status' => ProjectStatus::Published,
                'published_at' => $publishedAt,
                'expires_at' => $publishedAt->copy()->addDays(Project::PUBLICATION_DAYS),
            ];
        });
    }

    /**
     * Publié mais expiré (pas encore clôturé par la tâche quotidienne).
     */
    public function expired(): static
    {
        return $this->published(now()->subDays(Project::PUBLICATION_DAYS + 1));
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::Closed,
            'published_at' => now()->subDays(10),
            'expires_at' => now()->addDays(Project::PUBLICATION_DAYS - 10),
            'closed_at' => now(),
        ]);
    }
}
