<?php

use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Directory\Actions\AcceptMatchRequest;
use App\Modules\Directory\Actions\BlockUser;
use App\Modules\Marketplace\Enums\QuoteNotice;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\SellerProfile;
use App\Modules\Marketplace\Notifications\QuoteNotification;
use App\Modules\Marketplace\Support\QuoteCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Devis structurés (décisions 36, 37, 49 et 50) : brouillon dans une demande acceptée, calcul
| en entiers avec les taux vérifiés, envoi qui fige le contenu, nouvelle version, retrait.
| Les taux utilisés ici sont des valeurs fictives de test.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
    Notification::fake();
    verifiedVatRate('fr.vat.rate.standard', 2000);
});

function draftQuoteFor(array $overrides = [], string $clientRole = 'particulier', array $sellerAttributes = []): array
{
    [$client, $provider, $matchRequest] = acceptedQuoteRequest($clientRole, $sellerAttributes);
    $uuid = test()->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid, $overrides))->assertCreated()->json('data.uuid');

    return [$client, $provider, Quote::query()->where('uuid', $uuid)->sole()];
}

describe('calcul en entiers', function (): void {
    it('calcule le HT par ligne et la TVA par taux, arrondis au centime', function (): void {
        $totals = QuoteCalculator::compute([
            ['quantity_milli' => 3500, 'unit_price_cents' => 45000, 'vat_rate_code' => 'a', 'vat_basis_points' => 2000],
            ['quantity_milli' => 1500, 'unit_price_cents' => 333, 'vat_rate_code' => 'b', 'vat_basis_points' => 550],
            ['quantity_milli' => 1000, 'unit_price_cents' => 30000, 'vat_rate_code' => 'a', 'vat_basis_points' => 2000],
        ]);

        expect($totals['lines'])->toBe([['total_ht_cents' => 157500], ['total_ht_cents' => 500], ['total_ht_cents' => 30000]])
            ->and($totals['breakdown'])->toBe([
                ['code' => 'a', 'basis_points' => 2000, 'base_ht_cents' => 187500, 'vat_cents' => 37500],
                ['code' => 'b', 'basis_points' => 550, 'base_ht_cents' => 500, 'vat_cents' => 28],
            ])
            ->and($totals['total_ht_cents'])->toBe(188000)
            ->and($totals['total_vat_cents'])->toBe(37528)
            ->and($totals['total_ttc_cents'])->toBe(225528);
    });

    it('arrondit le demi-centime vers le haut', function (int $numerator, int $denominator, int $expected): void {
        expect(QuoteCalculator::roundedDivision($numerator, $denominator))->toBe($expected);
    })->with([[1499, 1000, 1], [1500, 1000, 2], [2500, 1000, 3], [0, 1000, 0], [9999, 10000, 1]]);

    it('refuse un total hors taxes au-delà du plafond, aux valeurs maximales sans dépassement de capacité', function (): void {
        $max = (int) config('marketplace.quotes.max_quantity_milli');
        $price = (int) config('marketplace.quotes.max_unit_price_cents');

        QuoteCalculator::compute(array_fill(0, 100, ['quantity_milli' => $max, 'unit_price_cents' => $price, 'vat_rate_code' => 'a', 'vat_basis_points' => 10_000]));
    })->throws(ValidationException::class);
});

describe('brouillon', function (): void {
    it('crée un brouillon calculé, invisible du client', function (): void {
        [$client, $provider, $matchRequest] = acceptedQuoteRequest();

        $response = $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.client_kind', 'consumer')
            ->assertJsonPath('data.viewer_role', 'provider')
            ->assertJsonPath('data.lines.0.total_ht_cents', 157500)
            ->assertJsonPath('data.totals', [
                'ht_cents' => 187500,
                'vat_cents' => 37500,
                'ttc_cents' => 225000,
                'vat_breakdown' => [['code' => 'fr.vat.rate.standard', 'basis_points' => 2000, 'base_ht_cents' => 187500, 'vat_cents' => 37500]],
            ])
            ->assertJsonPath('data.issuer', null);

        $uuid = $response->json('data.uuid');
        $this->actingAs($client)->getJson("/api/v1/me/quotes/{$uuid}")->assertNotFound();
        $this->actingAs($client)->getJson('/api/v1/me/quotes?box=received')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($provider)->getJson('/api/v1/me/quotes?box=sent')->assertOk()->assertJsonCount(1, 'data');
    });

    it('réserve la rédaction au prestataire d\'une demande acceptée', function (): void {
        [$client, $provider, $matchRequest] = acceptedQuoteRequest();

        $this->actingAs($client)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))->assertForbidden();
        $this->actingAs(marketplaceProvider())->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))->assertNotFound();
        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'))->assertNotFound();

        $pending = pendingProposal($provider, publishedProjectOf(projectOwner('particulier')));
        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($pending->uuid))->assertConflict();
    });

    it('refuse un devis entre deux utilisateurs qui se bloquent (403)', function (): void {
        [$client, $provider, $matchRequest] = acceptedQuoteRequest();
        app(BlockUser::class)->handle($client, $provider->id);

        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))->assertForbidden();
    });

    it('exige une identité professionnelle, avec un médiateur pour un consommateur (409)', function (): void {
        [, $provider, $matchRequest] = acceptedQuoteRequest(sellerAttributes: ['mediator_name' => null, 'mediator_url' => null]);

        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))
            ->assertConflict()
            ->assertJsonPath('code', 'seller_profile_incomplete')
            ->assertJsonPath('missing', ['mediator_name', 'mediator_url']);

        [, $professionalProvider, $businessRequest] = acceptedQuoteRequest('entrepreneur', ['mediator_name' => null, 'mediator_url' => null]);
        $this->actingAs($professionalProvider)->postJson('/api/v1/me/quotes', quotePayload($businessRequest->uuid))
            ->assertCreated()
            ->assertJsonPath('data.client_kind', 'business');

        [, $foreignProvider, $foreignRequest] = acceptedQuoteRequest(sellerAttributes: ['country' => 'BE']);
        $this->actingAs($foreignProvider)->postJson('/api/v1/me/quotes', quotePayload($foreignRequest->uuid))
            ->assertConflict()
            ->assertJsonPath('missing', ['country']);
    });

    it('refuse tout devis tant qu\'aucun taux de TVA n\'est vérifié (409 vat_rates_unavailable)', function (): void {
        ComplianceRule::query()->delete();
        [, $provider, $matchRequest] = acceptedQuoteRequest();

        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))
            ->assertConflict()
            ->assertJsonPath('code', 'vat_rates_unavailable');
    });

    it('refuse un taux inconnu, non vérifié, ou un taux exclusif combiné à un autre (422)', function (): void {
        verifiedVatRate('fr.vat.franchise', 0, true, ['fr' => 'Mention de test', 'en' => null]);
        ComplianceRule::factory()->vatRate('fr.vat.rate.draft', 1000)->create();
        [, $provider, $matchRequest] = acceptedQuoteRequest();
        $line = quotePayload()['lines'][0];

        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid, ['lines' => [[...$line, 'vat_rate_code' => 'fr.vat.rate.draft']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.vat_rate_code');

        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid, ['lines' => [$line, [...$line, 'vat_rate_code' => 'fr.vat.franchise']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
    });

    it('refuse un contenu invalide et les champs fixés par la plateforme (422)', function (array $overrides, string $field): void {
        [, $provider, $matchRequest] = acceptedQuoteRequest();

        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid, $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Quote::query()->count())->toBe(0);
    })->with([
        'statut imposé' => [['status' => 'accepted'], 'status'],
        'total imposé' => [['total_ttc_cents' => 1], 'total_ttc_cents'],
        'émetteur imposé' => [['issuer' => ['legal_name' => 'Autre']], 'issuer'],
        'numéro imposé' => [['number' => 'D-2027-9999'], 'number'],
        'aucune ligne' => [['lines' => []], 'lines'],
        'quantité décimale' => [['lines' => [['line_type' => 'labour', 'designation' => 'Main d\'œuvre', 'quantity_milli' => 1.5, 'unit' => 'hour', 'unit_price_cents' => 100, 'vat_rate_code' => 'fr.vat.rate.standard']]], 'lines.0.quantity_milli'],
        'prix négatif' => [['lines' => [['line_type' => 'labour', 'designation' => 'Main d\'œuvre', 'quantity_milli' => 1000, 'unit' => 'hour', 'unit_price_cents' => -1, 'vat_rate_code' => 'fr.vat.rate.standard']]], 'lines.0.unit_price_cents'],
        'unité inconnue' => [['lines' => [['line_type' => 'labour', 'designation' => 'Main d\'œuvre', 'quantity_milli' => 1000, 'unit' => 'litre', 'unit_price_cents' => 100, 'vat_rate_code' => 'fr.vat.rate.standard']]], 'lines.0.unit'],
        'clé inconnue dans une ligne' => [['lines' => [['line_type' => 'labour', 'designation' => 'Main d\'œuvre', 'quantity_milli' => 1000, 'unit' => 'hour', 'unit_price_cents' => 100, 'vat_rate_code' => 'fr.vat.rate.standard', 'vat_basis_points' => 0]]], 'lines.0'],
        'lieu d\'exécution absent' => [['client' => ['name' => 'Alex Martin']], 'client.execution_place'],
        'HTML' => [['scope' => '<script>alert(1)</script> conception et réalisation du site'], 'scope'],
        'validité trop longue' => [['validity_days' => 181], 'validity_days'],
        'devis payant sans montant' => [['quote_is_free' => false], 'quote_fee_cents'],
        'acompte sans nature' => [['deposit_cents' => 10000], 'deposit_kind'],
        'date de début passée' => [['start_date' => '2027-01-01'], 'start_date'],
        'offre d\'un autre prestataire' => [['offer' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'], 'offer'],
    ]);

    it('n\'ouvre qu\'un seul brouillon à la fois par demande (409)', function (): void {
        [, $provider, $matchRequest] = acceptedQuoteRequest();
        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))->assertCreated();

        $this->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid))->assertConflict();
    });

    it('remplace un brouillon, et cache le brouillon d\'un autre prestataire', function (): void {
        [, $provider, $quote] = draftQuoteFor();

        $this->actingAs($provider)->putJson("/api/v1/me/quotes/{$quote->uuid}", quotePayload(overrides: [
            'title' => 'Site vitrine et boutique',
            'lines' => [['line_type' => 'labour', 'designation' => 'Développement', 'quantity_milli' => 2000, 'unit' => 'day', 'unit_price_cents' => 50000, 'vat_rate_code' => 'fr.vat.rate.standard']],
        ]))
            ->assertOk()
            ->assertJsonPath('data.title', 'Site vitrine et boutique')
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.totals.ttc_cents', 120000);

        $this->actingAs(marketplaceProvider())->putJson("/api/v1/me/quotes/{$quote->uuid}", quotePayload())->assertNotFound();
        $this->actingAs(marketplaceProvider())->deleteJson("/api/v1/me/quotes/{$quote->uuid}")->assertNotFound();
    });

    it('supprime un brouillon, jamais un devis envoyé', function (): void {
        [, $provider, $draft] = draftQuoteFor();
        $this->actingAs($provider)->deleteJson("/api/v1/me/quotes/{$draft->uuid}")->assertNoContent();

        [, $sender, $sent] = sentQuote();
        $this->actingAs($sender)->deleteJson("/api/v1/me/quotes/{$sent->uuid}")->assertConflict();
    });
});

describe('envoi', function (): void {
    it('fige le contenu, numérote, fixe la validité et prévient le client sans montant', function (): void {
        [$client, $provider, $quote] = draftQuoteFor();

        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.number', 'D-2027-0001')
            ->assertJsonPath('data.validity.valid_until', '2027-04-09')
            ->assertJsonPath('data.validity.expires_at', '2027-04-09T21:59:59Z')
            ->assertJsonPath('data.issuer.legal_name', 'Camille Exemple')
            ->assertJsonPath('data.issuer.registration_number', '123456782')
            ->assertJsonPath('data.issuer.mediator_name', 'Médiateur Exemple')
            ->assertJsonPath('data.lines.0.vat.label.fr', 'Taux fr.vat.rate.standard')
            ->assertJsonPath('data.mentions.consumer.withdrawal_days', 14);

        $quote->refresh();
        expect($quote->content_hash)->toHaveLength(64)
            ->and($quote->lines->first()?->vat_rate_version)->toBe(1);

        Notification::assertSentTo($client, QuoteNotification::class, function (QuoteNotification $notification) use ($client): bool {
            $mail = $notification->toMail($client)->render();

            return $notification->notice === QuoteNotice::Received
                && $notification->notificationCategory() === 'marketplace.quotes'
                && ! str_contains((string) $mail, '2 250')
                && ! str_contains((string) $mail, 'Camille');
        });

        $this->actingAs($client)->getJson("/api/v1/me/quotes/{$quote->uuid}")
            ->assertOk()
            ->assertJsonPath('data.viewer_role', 'client')
            ->assertJsonPath('data.provider.slug', providerSlug($provider));
        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/send")->assertConflict();

        expect(json_encode(securityLog('marketplace.quote_sent')[0]->context))->not->toContain('225000');
    });

    it('numérote les devis du prestataire à la suite, et repart à 1 chaque année', function (): void {
        [, $provider, $first] = sentQuote();
        expect($first->number)->toBe('D-2027-0001');

        $sendAnother = function () use ($provider): string {
            $matchRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf(projectOwner('particulier'))));
            $uuid = test()->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid, ['start_date' => null]))->assertCreated()->json('data.uuid');

            return (string) test()->actingAs($provider)->postJson("/api/v1/me/quotes/{$uuid}/send")->assertOk()->json('data.number');
        };

        SellerProfile::query()->where('user_id', $provider->id)->update(['last_quote_sequence' => 41]);
        expect($sendAnother())->toBe('D-2027-0042');

        $this->travelTo(Carbon::parse('2028-01-05 10:00:00'));
        expect($sendAnother())->toBe('D-2028-0001');
    });

    it('refuse l\'envoi par le client (403) et un taux retiré depuis le brouillon (422)', function (): void {
        [$client, $provider, $quote] = draftQuoteFor();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/send")->assertNotFound();

        verifiedVatRate('fr.vat.rate.other', 1000);
        ComplianceRule::query()->where('code', 'fr.vat.rate.standard')->update(['valid_until' => '2027-03-01']);

        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/send")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.vat_rate_code');
    });

    it('limite les envois (429)', function (): void {
        config(['security.rate_limits.quote_sends.per_hour' => 1]);
        [, $provider, $quote] = draftQuoteFor();

        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/send")->assertOk();
        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/send")->assertTooManyRequests();
    });
});

describe('nouvelle version et retrait', function (): void {
    it('crée une nouvelle version qui garde le numéro et remplace la précédente à l\'envoi', function (): void {
        [$client, $provider, $quote] = sentQuote();

        $revision = $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/revise")
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.number', 'D-2027-0001')
            ->json('data.uuid');

        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/revise")->assertConflict();
        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/revise")->assertForbidden();

        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$revision}/send")
            ->assertOk()
            ->assertJsonPath('data.number', 'D-2027-0001')
            ->assertJsonPath('data.version', 2);

        expect($quote->fresh()->status)->toBe(QuoteStatus::Superseded);
        $this->actingAs($client)->getJson('/api/v1/me/quotes?box=received&status=sent')->assertJsonCount(1, 'data');
    });

    it('retire un devis envoyé, pas un brouillon', function (): void {
        [$client, $provider, $quote] = sentQuote();

        $this->actingAs($client)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdraw")->assertForbidden();
        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->actingAs($provider)->postJson("/api/v1/me/quotes/{$quote->uuid}/withdraw")->assertConflict();
    });
});

describe('confidentialité', function (): void {
    it('cache le devis à tout autre utilisateur, admin compris (404)', function (): void {
        [, , $quote] = sentQuote();
        $admin = moderator();

        $this->actingAs($admin)->getJson("/api/v1/me/quotes/{$quote->uuid}")->assertNotFound();
        $this->actingAs(projectOwner('particulier'))->getJson("/api/v1/me/quotes/{$quote->uuid}")->assertNotFound();
    });

    it('n\'expose aucun identifiant interne ni coordonnée d\'utilisateur', function (): void {
        [$client, , $quote] = sentQuote();
        $response = $this->actingAs($client)->getJson("/api/v1/me/quotes/{$quote->uuid}")->assertOk();
        $data = $response->json('data');
        unset($data['issuer']);

        expectNoPrivateFields($data);
        expect($response->getContent())->not->toContain($client->email);
    });
});
