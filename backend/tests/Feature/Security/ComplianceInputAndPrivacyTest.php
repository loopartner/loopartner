<?php

use App\Modules\Compliance\Models\ComplianceRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Suivi administratif : aucune donnée privée (numéro d'entreprise, nom, montant, note, jeton)
| dans le journal security ; aucune règle non vérifiée montrée aux utilisateurs ; aucun
| identifiant interne dans les réponses.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
    verifiedLegalForm();
    ComplianceRule::factory()->verified()->threshold('fr.test.seuil', 1_000_000)->create();
    verifiedDeadlineRule('fr.test.cfe', ['type' => 'yearly', 'month' => 12, 'day' => 15]);
    ComplianceRule::factory()->deadline('fr.test.brouillon-secret', ['type' => 'monthly', 'day' => 1])->create(['title' => ['fr' => 'BROUILLON NON VERIFIE', 'en' => null]]);
    ComplianceRule::factory()->threshold('fr.test.seuil-brouillon', 1)->create(['title' => ['fr' => 'BROUILLON NON VERIFIE', 'en' => null]]);
});

/**
 * Parcours complet d'un utilisateur, qui renvoie toutes les réponses JSON obtenues.
 *
 * @return list<array<mixed>>
 */
function complianceJourneyResponses(): array
{
    $user = complianceUser();
    makePremium($user);
    $test = test();
    $responses = [];

    $company = $test->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload(['name' => 'Société Confidentielle', 'vat_number' => 'FR12123456789']))->assertCreated();
    $responses[] = $company->json();
    $uuid = $company->json('data.uuid');
    $base = "/api/v1/me/compliance/companies/{$uuid}";

    $responses[] = $test->actingAs($user)->postJson("{$base}/deadlines", ['title' => 'Titre confidentiel', 'due_on' => '2026-12-01', 'notes' => 'Note confidentielle'])->assertCreated()->json();
    $responses[] = $test->actingAs($user)->putJson("{$base}/revenue/2026-10", ['lines' => ['services_commercial' => 987_654]])->assertOk()->json();
    $responses[] = $test->actingAs($user)->postJson('/api/v1/me/compliance/calendar-feed')->assertCreated()->json();

    foreach (['/api/v1/me/compliance', '/api/v1/me/compliance/companies', '/api/v1/me/compliance/deadlines?to=2027-10-31', "{$base}/deadlines?to=2027-10-31", "{$base}/thresholds", "{$base}/estimate", "{$base}/revenue", '/api/v1/me/compliance/settings', '/api/v1/me/compliance/export', '/api/v1/compliance/countries/FR/questionnaire'] as $uri) {
        $responses[] = $test->actingAs($user)->getJson($uri)->assertOk()->json();
    }

    return $responses;
}

it('n\'écrit jamais de numéro, de nom, de montant, de note ni de jeton dans le journal security', function (): void {
    $responses = complianceJourneyResponses();
    $token = substr(basename((string) parse_url((string) $responses[3]['data']['url'], PHP_URL_PATH)), 0, -4);
    $log = json_encode(array_map(fn ($record) => [$record->message, $record->context], securityLog()));

    foreach (['12345678900012', 'FR12123456789', 'Confidentielle', 'confidentiel', '987654', '987 654', substr($token, 16)] as $secret) {
        expect($log)->not->toContain($secret);
    }

    expect(securityLog('compliance.company_created'))->toHaveCount(1)
        ->and(securityLog('compliance.calendar_feed_created'))->toHaveCount(1)
        ->and(securityLog('compliance.export_downloaded'))->toHaveCount(1);
});

it('ne montre jamais une règle non vérifiée, et aucun identifiant interne', function (): void {
    foreach (complianceJourneyResponses() as $response) {
        expect(json_encode($response))->not->toContain('BROUILLON NON VERIFIE')->not->toContain('brouillon-secret');
        expectNoPrivateFields($response);
    }
});

it('renvoie l\'avertissement dans chaque réponse du suivi administratif', function (): void {
    foreach (complianceJourneyResponses() as $response) {
        expect($response)->toHaveKey('disclaimer')
            ->and($response['disclaimer']['text'])->toContain('expert-comptable');
    }
});

it('refuse les caractères invisibles et le HTML dans tous les textes saisis', function (string $field, string $uri, array $payload): void {
    $user = complianceUser();
    $company = complianceCompanyOf($user);
    $uri = str_replace('{company}', $company->uuid, $uri);

    foreach (["Texte\u{202E}inversé", "Zéro\u{200B}largeur", '<script>alert(1)</script>'] as $value) {
        $this->actingAs($user)->json(str_contains($uri, '/companies/') && ! str_ends_with($uri, 's') ? 'PATCH' : 'POST', $uri, [...$payload, $field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }
})->with([
    'nom d\'entreprise' => ['name', '/api/v1/me/compliance/companies/{company}', []],
    'titre d\'échéance' => ['title', '/api/v1/me/compliance/companies/{company}/deadlines', ['due_on' => '2027-01-01']],
    'notes d\'échéance' => ['notes', '/api/v1/me/compliance/companies/{company}/deadlines', ['title' => 'Échéance', 'due_on' => '2027-01-01']],
    'titre de tâche' => ['title', '/api/v1/me/compliance/companies/{company}/tasks', []],
]);
