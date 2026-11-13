<?php

namespace Database\Factories\Modules\Compliance;

use App\Models\User;
use App\Modules\Compliance\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Entreprise de test : micro-entreprise fictive de services, créée le 1er mars 2026. Le
 * calendrier n'est pas généré par la factory (voir CalendarGenerator).
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'name' => 'Studio Exemple',
            'country' => 'FR',
            'legal_form_code' => 'fr.legal_form.micro',
            'activity_types' => ['services_commercial'],
            'vat_regime' => 'franchise',
            'created_on' => '2026-03-01',
            'fiscal_year_end' => '12-31',
            'options' => ['declaration_frequency' => 'quarterly'],
            'registration_number' => null,
            'vat_number' => null,
        ];
    }
}
