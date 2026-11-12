<?php

namespace Database\Factories\Modules\Accounts;

use App\Models\User;
use App\Modules\Accounts\Enums\PremiumSource;
use App\Modules\Accounts\Models\PremiumGrant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Période premium manuelle, commencée hier, sans fin.
 *
 * @extends Factory<PremiumGrant>
 */
class PremiumGrantFactory extends Factory
{
    protected $model = PremiumGrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'source' => PremiumSource::Manual,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);
    }
}
