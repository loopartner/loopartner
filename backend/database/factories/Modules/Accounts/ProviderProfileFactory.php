<?php

namespace Database\Factories\Modules\Accounts;

use App\Models\User;
use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProviderProfile>
 */
class ProviderProfileFactory extends Factory
{
    protected $model = ProviderProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $displayName = fake()->company();

        return [
            'user_id' => User::factory(),
            'display_name' => $displayName,
            'slug' => Str::slug($displayName).'-'.Str::lower(Str::random(6)),
            'headline' => fake()->sentence(6),
            'bio' => fake()->paragraphs(2, true),
            'city' => fake()->city(),
            'country' => 'FR',
            'website' => 'https://'.fake()->domainName(),
            'status' => ProviderProfileStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ProviderProfileStatus::Published,
            'published_at' => now(),
        ]);
    }
}
