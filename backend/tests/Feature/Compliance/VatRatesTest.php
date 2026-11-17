<?php

use App\Modules\Compliance\Actions\GetVatRates;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleContent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Taux de TVA (règles de type vat_rate) : saisis et vérifiés comme les autres règles, lus par le
| module Marketplace pour les lignes de devis (Action publique GetVatRates). Aucun taux n'est
| écrit dans le code ; les brouillons livrés n'en contiennent aucun.
*/

const FR_2027_VAT_DRAFTS = 'database/data/compliance/fr-2027-vat.draft.json';

beforeEach(function (): void {
    Carbon::setTestNow('2027-02-01 10:00:00');
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function vatRulePayload(array $payload = []): array
{
    return [
        'country' => 'FR',
        'kind' => 'vat_rate',
        'code' => 'fr.test.vat-rate',
        'valid_from' => '2027-01-01',
        'valid_until' => '2027-12-31',
        'title' => ['fr' => 'Taux de TVA de test', 'en' => null],
        'conditions' => ['legal_forms' => [], 'activity_types' => [], 'vat_regimes' => [], 'options' => []],
        'payload' => [
            'basis_points' => 1234,
            'label' => ['fr' => 'Taux de test', 'en' => 'Test rate'],
            'scope' => ['fr' => 'Champ d\'application de test.', 'en' => null],
            'mention' => null,
            'client_certification' => ['fr' => 'Je certifie les conditions de test.', 'en' => null],
            'exclusive' => false,
            ...$payload,
        ],
        'source_url' => 'https://www.impots.gouv.fr/une-page-de-test',
        'source_label' => 'Page de test',
    ];
}

describe('administration', function (): void {
    it('crée un taux en brouillon avec un contenu reconstruit, puis le vérifie', function (): void {
        $uuid = asModerator()->postJson('/api/v1/admin/compliance/rules', vatRulePayload())
            ->assertCreated()
            ->assertJsonPath('data.kind', 'vat_rate')
            ->assertJsonPath('data.payload.basis_points', 1234)
            ->assertJsonPath('data.payload.client_certification.fr', 'Je certifie les conditions de test.')
            ->assertJsonPath('data.payload.mention', null)
            ->json('data.uuid');

        asModerator()->postJson("/api/v1/admin/compliance/rules/{$uuid}/verify", [
            'version' => 1,
            'verification_note' => 'Vérifié par le cabinet Exemple Conseil le 2027-01-10 sur la page source.',
        ])->assertOk()->assertJsonPath('data.status', 'verified');
    });

    it('refuse une clé inconnue ou un taux hors bornes', function (array $payload, string $field): void {
        asModerator()->postJson('/api/v1/admin/compliance/rules', vatRulePayload($payload))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'clé inconnue' => [['amount' => 10], 'payload'],
        'taux décimal' => [['basis_points' => 20.5], 'payload.basis_points'],
        'taux au-delà de 100 %' => [['basis_points' => 10_001], 'payload.basis_points'],
        'mention en HTML' => [['mention' => ['fr' => '<b>TVA</b>', 'en' => null]], 'payload.mention.fr'],
    ]);

    it('ne vérifie pas un taux sans valeur ni champ d\'application', function (): void {
        $uuid = asModerator()->postJson('/api/v1/admin/compliance/rules', vatRulePayload(['basis_points' => null, 'scope' => null]))
            ->assertCreated()
            ->json('data.uuid');

        $response = asModerator()->postJson("/api/v1/admin/compliance/rules/{$uuid}/verify", [
            'version' => 1,
            'verification_note' => 'Vérifié par le cabinet Exemple Conseil le 2027-01-10 sur la page source.',
        ])->assertUnprocessable()->assertJsonValidationErrors('rule');

        expect($response->json('errors.rule.0'))->toContain('payload.basis_points', 'payload.scope.fr');
    });
});

describe('lecture par les autres modules (GetVatRates)', function (): void {
    it('ne renvoie que les taux vérifiés, en vigueur ce jour-là, du pays demandé', function (): void {
        verifiedVatRate('fr.vat.rate.standard', 2000);
        verifiedVatRate('fr.vat.franchise', 0, true, ['fr' => 'Mention de test', 'en' => null]);
        verifiedVatRate('fr.vat.rate.old', 1900, attributes: ['valid_from' => '2020-01-01', 'valid_until' => '2026-12-31']);
        ComplianceRule::factory()->vatRate('fr.vat.rate.draft', 1000)->create();
        ComplianceRule::factory()->verified()->vatRate('be.vat.rate.standard', 2100)->create(['country' => 'BE']);
        ComplianceRule::factory()->verified()->rate('fr.micro.social.services', 2120)->create();

        $rates = app(GetVatRates::class)->handle('FR', Carbon::parse('2027-02-01'));

        expect(array_column($rates, 'code'))->toBe(['fr.vat.franchise', 'fr.vat.rate.standard'])
            ->and($rates[0])->toMatchArray(['basis_points' => 0, 'exclusive' => true, 'mention' => ['fr' => 'Mention de test', 'en' => null], 'version' => 1])
            ->and(app(GetVatRates::class)->find('FR', 'fr.vat.rate.old', Carbon::parse('2026-06-01'))['basis_points'])->toBe(1900)
            ->and(app(GetVatRates::class)->find('FR', 'fr.micro.social.services', Carbon::parse('2027-02-01')))->toBeNull()
            ->and(app(GetVatRates::class)->find('FR', 'fr.vat.rate.draft', Carbon::parse('2027-02-01')))->toBeNull();
    });

    it('ignore les taux de démonstration hors du poste local', function (): void {
        ComplianceRule::factory()->verified()->demo()->vatRate('demo.vat.rate.standard', 2000)->create();

        expect(app(GetVatRates::class)->handle('FR', Carbon::parse('2027-02-01')))->toBe([]);
    });
});

describe('fichier livré des taux 2027', function (): void {
    it('s\'importe en brouillons, sans aucun taux rempli, et signale chaque manque', function (): void {
        $rules = json_decode((string) file_get_contents(base_path(FR_2027_VAT_DRAFTS)), true)['rules'];

        foreach ($rules as $rule) {
            $notes = (string) ($rule['internal_notes'] ?? '');
            $model = new ComplianceRule;
            $model->forceFill([...RuleContent::normalize(RuleKind::from($rule['kind']), $rule), 'kind' => $rule['kind']]);

            expect($rule['kind'])->toBe('vat_rate')
                ->and($rule['payload']['basis_points'])->toBeNull("{$rule['code']} : taux")
                ->and($rule['payload']['mention'])->toBeNull("{$rule['code']} : mention")
                ->and($rule['payload']['client_certification'])->toBeNull("{$rule['code']} : certification")
                ->and($notes)->toMatch('/lue? sur https:\/\/[^ ]+ le 2026-11-16/iu')
                ->and(RuleContent::missingFields($model))->not->toBe([])
                ->and($notes)->toContain('à compléter');
        }

        $this->artisan('compliance:import-rules', ['path' => FR_2027_VAT_DRAFTS])->assertSuccessful();

        expect(ComplianceRule::query()->count())->toBe(count($rules))
            ->and(ComplianceRule::query()->where('status', '!=', RuleStatus::Draft->value)->count())->toBe(0)
            ->and(app(GetVatRates::class)->handle('FR', Carbon::parse('2027-02-01')))->toBe([]);
    });
});
