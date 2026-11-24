<?php

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use Illuminate\Support\Carbon;

/*
| Taux de TVA utilisables dans un devis (GET /me/quotes/vat-rates) : règles vérifiées en vigueur
| du pays du prestataire, lues par le formulaire de devis. Les taux sont des valeurs fictives de test.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
});

it('liste les taux vérifiés en vigueur pour un prestataire', function (): void {
    verifiedVatRate('fr.vat.rate.standard', 2000);
    verifiedVatRate('fr.vat.franchise', 0, exclusive: true, mention: ['fr' => 'TVA non applicable', 'en' => 'VAT not applicable']);
    verifiedVatRate('fr.vat.rate.reduced', 1000, attributes: ['valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);

    $response = $this->actingAs(marketplaceProvider())->getJson('/api/v1/me/quotes/vat-rates')->assertOk();

    expect($response->json('meta'))->toBe(['country' => 'FR', 'available' => true])
        ->and(collect($response->json('data'))->pluck('code')->all())->toBe(['fr.vat.franchise', 'fr.vat.rate.standard'])
        ->and(array_keys($response->json('data.0')))->toBe(['code', 'basis_points', 'label', 'scope', 'exclusive', 'requires_certification'])
        ->and($response->json('data.0.exclusive'))->toBeTrue();
});

it('annonce une liste vide sans aucun taux vérifié', function (): void {
    $this->actingAs(browsingProvider())->getJson('/api/v1/me/quotes/vat-rates')
        ->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['country' => 'FR', 'available' => false]]);
});

it('est réservé aux prestataires connectés avec un email vérifié', function (): void {
    $this->getJson('/api/v1/me/quotes/vat-rates')->assertUnauthorized();

    $client = User::factory()->create();
    $client->assignRole(AccountType::Particulier->value);
    $this->actingAs($client)->getJson('/api/v1/me/quotes/vat-rates')->assertForbidden();

    $unverified = User::factory()->unverified()->create();
    $unverified->assignRole(AccountType::Prestataire->value);
    $this->actingAs($unverified)->getJson('/api/v1/me/quotes/vat-rates')->assertForbidden();
});
