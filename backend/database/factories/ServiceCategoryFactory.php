<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'parent_id' => null,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => ['fr' => $name, 'en' => $name.' (en)'],
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function childOf(ServiceCategory $parent): static
    {
        return $this->state(fn (): array => ['parent_id' => $parent->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
