<?php

namespace Database\Factories\Modules\Marketplace;

use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Devis de test en brouillon, sans ligne. La demande de mise en relation (match_request_id) et
 * les parties sont à fournir : les tests passent plutôt par l'API (helpers de tests/Pest.php).
 *
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'client_kind' => 'consumer',
            'status' => QuoteStatus::Draft,
            'title' => 'Création d\'un site vitrine',
            'scope' => 'Conception et réalisation d\'un site vitrine de cinq pages, mise en ligne comprise.',
            'client_snapshot' => ['name' => 'Client Exemple', 'company_name' => null, 'registration_number' => null, 'address' => null, 'execution_place' => 'À distance'],
            'currency' => 'EUR',
            'validity_days' => 30,
        ];
    }
}
