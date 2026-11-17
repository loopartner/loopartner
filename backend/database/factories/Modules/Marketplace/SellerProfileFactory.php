<?php

namespace Database\Factories\Modules\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\SellerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Identité professionnelle de test : micro-entreprise fictive établie en France, SIREN de forme
 * valide (clé de Luhn) qui ne désigne aucune entreprise réelle connue, autocertification à jour,
 * avec un médiateur (devis aux consommateurs possibles).
 *
 * @extends Factory<SellerProfile>
 */
class SellerProfileFactory extends Factory
{
    protected $model = SellerProfile::class;

    public const SIREN = '123456782';

    public const VAT_NUMBER = 'FR11123456782';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'legal_name' => 'Camille Exemple',
            'trade_name' => 'Atelier Exemple',
            'legal_form' => 'ei_micro',
            'registration_number' => self::SIREN,
            'registry' => 'rne',
            'registry_city' => null,
            'share_capital_cents' => null,
            'vat_number' => null,
            'address_line1' => '1 rue de l\'Exemple',
            'address_line2' => null,
            'postal_code' => '75001',
            'city' => 'Paris',
            'country' => 'FR',
            'phone' => '+33 1 23 45 67 89',
            'email' => 'contact@atelier-exemple.test',
            'insurer_name' => 'Assurance Exemple',
            'insurer_contact' => '10 avenue de l\'Assureur, 69001 Lyon',
            'insurance_coverage' => 'France métropolitaine',
            'insurance_ten_year' => false,
            'mediator_name' => 'Médiateur Exemple',
            'mediator_url' => 'https://www.mediateur-exemple.fr',
            'self_certified_at' => now(),
            'self_certification_version' => (string) config('marketplace.self_certification.version'),
        ];
    }

    /**
     * Sans médiateur de la consommation : aucun devis possible envers un particulier.
     */
    public function withoutMediator(): static
    {
        return $this->state(fn (): array => ['mediator_name' => null, 'mediator_url' => null]);
    }
}
