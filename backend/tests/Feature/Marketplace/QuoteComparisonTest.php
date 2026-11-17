<?php

use App\Models\User;
use App\Modules\Directory\Actions\AcceptMatchRequest;
use App\Modules\Directory\Models\Project;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Support\ComparisonPdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Comparateur de devis (décisions 33 et 50) : devis reçus côte à côte, éléments manquants et
| questions à poser, par des règles explicables (chaque constat donne sa raison). Liste gratuite,
| comparaison et export PDF Premium. Valeurs fictives de test.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
    Notification::fake();
    verifiedVatRate('fr.vat.rate.standard', 2000);
});

/**
 * Devis envoyé par un nouveau prestataire sur le projet donné.
 *
 * @param  array<string, mixed>  $overrides
 */
function quoteOnProject(Project $project, array $overrides = [], array $sellerAttributes = []): Quote
{
    $provider = marketplaceProvider($sellerAttributes);
    $matchRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, $project));
    $uuid = test()->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid, $overrides))->assertCreated()->json('data.uuid');
    test()->actingAs($provider)->postJson("/api/v1/me/quotes/{$uuid}/send")->assertOk();

    return Quote::query()->where('uuid', $uuid)->sole();
}

/**
 * Trois devis sur un projet à réaliser sur place : [porteur, projet, A, B, C].
 *
 * @return array{0: User, 1: Project, 2: Quote, 3: Quote, 4: Quote}
 */
function threeQuotes(): array
{
    $owner = projectOwner('particulier');
    $project = Project::factory()->published()->create(['user_id' => $owner->id, 'remote_ok' => false, 'title' => 'Rénovation de la cuisine']);
    $labour = ['line_type' => 'labour', 'designation' => 'Main d\'œuvre', 'quantity_milli' => 10000, 'unit' => 'hour', 'unit_price_cents' => 5000, 'vat_rate_code' => 'fr.vat.rate.standard'];
    $travel = ['line_type' => 'travel', 'designation' => 'Déplacement', 'quantity_milli' => 1000, 'unit' => 'lump_sum', 'unit_price_cents' => 5000, 'vat_rate_code' => 'fr.vat.rate.standard'];
    $disposal = ['line_type' => 'disposal', 'designation' => 'Évacuation des gravats', 'quantity_milli' => 1000, 'unit' => 'lump_sum', 'unit_price_cents' => 10000, 'vat_rate_code' => 'fr.vat.rate.standard'];

    $a = quoteOnProject($project, ['lines' => [$labour, $travel, $disposal], 'start_date' => '2027-04-01']);
    test()->travel(1)->minutes();
    $b = quoteOnProject($project, [
        'lines' => [['line_type' => 'service', 'designation' => 'Rénovation complète', 'quantity_milli' => 1000, 'unit' => 'lump_sum', 'unit_price_cents' => 60000, 'vat_rate_code' => 'fr.vat.rate.standard']],
        'start_date' => null, 'duration' => null, 'payment_terms' => null, 'deposit_cents' => 20000, 'deposit_kind' => 'arrhes',
    ], ['insurer_name' => null, 'insurer_contact' => null, 'insurance_coverage' => null]);
    test()->travel(1)->minutes();
    $c = quoteOnProject($project, ['lines' => [[...$labour, 'quantity_milli' => 3000, 'unit' => 'day', 'unit_price_cents' => 50000], $travel, $disposal], 'start_date' => '2027-05-01', 'validity_days' => 5]);

    return [$owner, $project, $a, $b, $c];
}

/**
 * @param  list<array<string, mixed>>  $findings
 * @return list<string>
 */
function rulesFor(array $findings, ?string $quoteUuid): array
{
    return array_values(array_map(fn (array $finding): string => $finding['rule'], array_filter($findings, fn (array $finding): bool => $finding['quote'] === $quoteUuid)));
}

describe('liste gratuite', function (): void {
    it('liste les devis reçus pour son projet, et cache le projet d\'autrui (404)', function (): void {
        [$owner, $project, $a] = threeQuotes();

        $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}/quotes")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.uuid', $a->uuid);

        $this->actingAs(projectOwner('particulier'))->getJson("/api/v1/me/projects/{$project->uuid}/quotes")->assertNotFound();
        $this->actingAs($a->provider)->getJson("/api/v1/me/projects/{$project->uuid}/quotes")->assertNotFound();
        $this->actingAs(moderator())->getJson("/api/v1/me/projects/{$project->uuid}/quotes/comparison")->assertNotFound();
    });

    it('réserve la comparaison et son export au Premium (403 premium_required)', function (): void {
        [$owner, $project] = threeQuotes();

        $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}/quotes/comparison")
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('feature', 'quote_comparison');
        $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}/quotes/comparison.pdf")
            ->assertForbidden()
            ->assertJsonPath('feature', 'quote_comparison_export');

        expect(securityLog('marketplace.premium_required'))->toHaveCount(2);
    });
});

describe('comparaison Premium', function (): void {
    it('présente les devis dans l\'ordre de réception, avec des constats expliqués', function (): void {
        [$owner, $project, $a, $b, $c] = threeQuotes();
        makePremium($owner);

        $response = $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}/quotes/comparison")->assertOk();
        $data = $response->json('data');
        $findings = $data['findings'];

        expect(array_column($data['quotes'], 'uuid'))->toBe([$a->uuid, $b->uuid, $c->uuid])
            ->and($data['project'])->toBe(['uuid' => $project->uuid, 'title' => 'Rénovation de la cuisine'])
            ->and($data['quotes'][1]['line_types'])->toBe(['service' => ['count' => 1, 'total_ht_cents' => 60000]])
            ->and($data['disclaimer'])->toContain('ni un avis sur la qualité');

        expect(rulesFor($findings, $b->uuid))->toContain(
            'missing_start_date', 'missing_duration', 'missing_payment_terms', 'deposit_requested',
            'missing_travel_costs', 'single_lump_sum', 'missing_insurance', 'missing_line_type',
        )
            ->and(rulesFor($findings, $a->uuid))->toBe(['earliest_start'])
            ->and(rulesFor($findings, $c->uuid))->toContain('validity_ending', 'price_gap')
            ->and(rulesFor($findings, null))->toBe(['mixed_units']);

        $lump = collect($findings)->firstWhere('rule', 'single_lump_sum');
        expect($lump['reason'])->toContain('100 %', $b->number)
            ->and($lump['question'])->toBe('Pouvez-vous détailler ce que comprend ce forfait ?');

        $gap = collect($findings)->first(fn (array $f): bool => $f['rule'] === 'price_gap' && $f['quote'] === $c->uuid);
        expect($gap['reason'])->toContain('au-dessus de la médiane', '3 devis comparés');

        $missingType = collect($findings)->first(fn (array $f): bool => $f['rule'] === 'missing_line_type' && str_contains($f['reason'], 'Déplacement'));
        expect($missingType['reason'])->toBe("2 devis sur 2 autres comportent une ligne « Déplacement », pas le devis {$b->number}.");

        expectNoPrivateFields($data);
    });

    it('n\'applique pas une règle désactivée', function (): void {
        config(['marketplace.comparison.rules.missing_insurance.enabled' => false]);
        [$owner, $project, , $b] = threeQuotes();
        makePremium($owner);

        $findings = $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}/quotes/comparison")->json('data.findings');

        expect(rulesFor($findings, $b->uuid))->not->toContain('missing_insurance');
    });

    it('ne compare ni les devis refusés, ni les devis masqués', function (): void {
        [$owner, $project, $a, $b, $c] = threeQuotes();
        makePremium($owner);
        $this->actingAs($owner)->postJson("/api/v1/me/quotes/{$a->uuid}/decline")->assertOk();
        restrictContent('quote', $b->uuid);

        $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}/quotes/comparison")
            ->assertOk()
            ->assertJsonPath('data.quotes.*.uuid', [$c->uuid]);
    });

    it('exporte la comparaison en PDF, texte échappé et avertissement compris', function (): void {
        [$owner, $project] = threeQuotes();
        makePremium($owner);

        $response = $this->actingAs($owner)->get("/api/v1/me/projects/{$project->uuid}/quotes/comparison.pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        expect(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');

        $html = ComparisonPdf::html(['quotes' => [], 'findings' => [['rule' => 'x', 'severity' => 'info', 'quote' => null, 'message' => '<b>test</b>', 'reason' => 'r', 'question' => null]]], 'Projet <script>', 'fr');
        expect($html)->toContain('&lt;b&gt;test&lt;/b&gt;', 'Projet &lt;script&gt;', 'ni un avis sur la qualité')->not->toContain('<script>');
    });

    it('limite les comparaisons (429)', function (): void {
        config(['security.rate_limits.quote_comparisons.per_hour' => 1]);
        [$owner, $project] = threeQuotes();
        makePremium($owner);

        $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}/quotes/comparison")->assertOk();
        $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}/quotes/comparison")->assertTooManyRequests();
    });
});
