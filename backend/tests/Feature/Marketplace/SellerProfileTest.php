<?php

use App\Models\User;
use App\Modules\Marketplace\Models\SellerProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Identité professionnelle du prestataire (décision 37) : mentions obligatoires de ses devis et
| qualité déclarée de ses offres. Privée, chiffrée en base, SIREN contrôlé dans sa forme.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
});

function sellerUser(string $role = 'prestataire'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

describe('accès', function (): void {
    it('réserve l\'identité professionnelle aux prestataires, admin compris (403)', function (string $role): void {
        $user = sellerUser($role);

        $this->actingAs($user)->getJson('/api/v1/me/seller-profile')->assertForbidden();
        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload())->assertForbidden();

        expect(SellerProfile::query()->count())->toBe(0);
    })->with(['particulier', 'entrepreneur', 'redacteur', 'admin']);

    it('répond 404 tant qu\'aucune identité n\'est enregistrée', function (): void {
        $this->actingAs(sellerUser())->getJson('/api/v1/me/seller-profile')->assertNotFound();
    });
});

describe('enregistrement', function (): void {
    it('crée puis remplace l\'identité, avec les numéros compactés et l\'autocertification datée', function (): void {
        $user = sellerUser();

        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload())
            ->assertCreated()
            ->assertJsonPath('data.registration_number', '123456782')
            ->assertJsonPath('data.vat_number', 'FR11123456782')
            ->assertJsonPath('data.self_certification.accepted_at', '2027-03-10T12:00:00Z')
            ->assertJsonPath('data.missing_for_offers', [])
            ->assertJsonPath('data.missing_for_quotes', [])
            ->assertJsonPath('data.missing_for_consumer_quotes', [])
            ->assertJsonPath('data.registration_verification.status', null);

        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload(['mediator_name' => null, 'mediator_url' => null, 'trade_name' => null]))
            ->assertOk()
            ->assertJsonPath('data.trade_name', null)
            ->assertJsonPath('data.missing_for_quotes', [])
            ->assertJsonPath('data.missing_for_consumer_quotes', ['mediator_name', 'mediator_url']);

        expect(SellerProfile::query()->count())->toBe(1)
            ->and(securityLog('marketplace.seller_profile_updated'))->toHaveCount(2);
    });

    it('chiffre l\'identité en base et ne l\'écrit pas dans le journal', function (): void {
        $user = sellerUser();
        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload())->assertCreated();

        $raw = json_encode(DB::table('marketplace_seller_profiles')->first());
        $log = json_encode(array_map(fn ($record): array => $record->context, securityLog()));

        foreach (['123456782', 'contact@atelier-exemple.test', 'Camille Exemple', 'rue de'] as $secret) {
            expect($raw)->not->toContain($secret)
                ->and($log)->not->toContain($secret);
        }
    });

    it('ignore l\'autocertification, la vérification et le propriétaire envoyés par le client', function (): void {
        $user = sellerUser();
        $other = sellerUser();

        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload([
            'user_id' => $other->id,
            'self_certified_at' => '2020-01-01T00:00:00Z',
            'registration_verification_status' => 'verified',
            'registration_verified_at' => '2020-01-01T00:00:00Z',
            'last_quote_sequence' => 999,
        ]))->assertCreated();

        $profile = SellerProfile::query()->sole();

        expect($profile->user_id)->toBe($user->id)
            ->and($profile->self_certified_at?->toIso8601ZuluString())->toBe('2027-03-10T12:00:00Z')
            ->and($profile->registration_verification_status)->toBeNull()
            ->and($profile->last_quote_sequence)->toBe(0);
    });

    it('efface une vérification d\'immatriculation passée quand le SIREN change', function (): void {
        $user = sellerUser();
        SellerProfile::factory()->create([
            'user_id' => $user->id,
            'registration_verification_status' => 'verified',
            'registration_verified_at' => now()->subMonth(),
        ]);

        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload(['registration_number' => '552100554', 'vat_number' => null]))
            ->assertOk()
            ->assertJsonPath('data.registration_verification.status', null);
    });

    it('accepte une entreprise établie hors de France, sans devis possible en V1', function (): void {
        $this->actingAs(sellerUser())->putJson('/api/v1/me/seller-profile', sellerProfilePayload([
            'country' => 'BE',
            'registration_number' => '0123.456.789',
            'vat_number' => 'BE0123456789',
            'postal_code' => '1000',
            'city' => 'Bruxelles',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.missing_for_offers', [])
            ->assertJsonPath('data.missing_for_quotes', ['country']);
    });

    it('demande d\'accepter de nouveau une autocertification dont le texte a changé', function (): void {
        $user = sellerUser();
        SellerProfile::factory()->create(['user_id' => $user->id]);
        config(['marketplace.self_certification.version' => '2027-01-01']);

        $this->actingAs($user)->getJson('/api/v1/me/seller-profile')
            ->assertOk()
            ->assertJsonPath('data.missing_for_offers', ['self_certification']);

        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload(['self_certification_version' => '2026-11-17']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('self_certification_version');
    });
});

describe('validation', function (): void {
    it('refuse une identité invalide ou incomplète', function (array $overrides, string $field): void {
        $this->actingAs(sellerUser())->putJson('/api/v1/me/seller-profile', sellerProfilePayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'SIREN à clé fausse' => [['registration_number' => '123456789'], 'registration_number'],
        'SIRET au lieu du SIREN' => [['registration_number' => '12345678200011'], 'registration_number'],
        'TVA d\'un autre SIREN' => [['vat_number' => 'FR96552100554'], 'vat_number'],
        'TVA à clé fausse' => [['vat_number' => 'FR12123456782'], 'vat_number'],
        'société sans capital' => [['legal_form' => 'sas'], 'share_capital_cents'],
        'RCS sans ville du greffe' => [['registry' => 'rcs'], 'registry_city'],
        'forme inconnue' => [['legal_form' => 'gmbh'], 'legal_form'],
        'code postal français invalide' => [['postal_code' => '7500'], 'postal_code'],
        'HTML' => [['legal_name' => '<script>alert(1)</script>'], 'legal_name'],
        'caractère invisible' => [['city' => "Pa\u{202E}ris"], 'city'],
        'médiateur sans site' => [['mediator_url' => null], 'mediator_url'],
        'site du médiateur non sécurisé' => [['mediator_url' => 'http://mediateur.example.com'], 'mediator_url'],
        'assureur sans couverture' => [['insurance_coverage' => null], 'insurance_coverage'],
        'autocertification refusée' => [['self_certification' => false], 'self_certification'],
        'email invalide' => [['email' => 'pas-un-email'], 'email'],
    ]);

    it('limite les enregistrements (429)', function (): void {
        config(['security.rate_limits.seller_profile_writes.per_hour' => 2]);
        $user = sellerUser();

        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload())->assertCreated();
        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload())->assertOk();
        $this->actingAs($user)->putJson('/api/v1/me/seller-profile', sellerProfilePayload())->assertTooManyRequests();
    });
});
