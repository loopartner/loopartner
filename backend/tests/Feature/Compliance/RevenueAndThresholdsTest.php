<?php

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\ThresholdAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Chiffre d'affaires (centimes, chiffré en base), seuils (prorata, ratio, projection, paliers)
| et estimation premium. Toutes les valeurs des règles sont fictives (règles de test).
|
| Horloge fixe : 13 novembre 2026. Entreprise créée le 1er mars 2026 : 306 jours d'activité en
| 2026 ; mois complets écoulés : mars à octobre (245 jours).
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
    verifiedLegalForm();
    // Seuil fictif de 10 000,00 proratisé l'année de création.
    ComplianceRule::factory()->verified()->threshold('fr.test.seuil', 1_000_000, ['services_commercial'], true)->create();
});

function revenueUri(Company $company, string $path): string
{
    return "/api/v1/me/compliance/companies/{$company->uuid}{$path}";
}

/**
 * Saisit 900,00 par mois de mars à octobre 2026 (720 000 centimes au total).
 */
function fillRevenue(Company $company): void
{
    foreach (range(3, 10) as $month) {
        test()->actingAs($company->user)->putJson(revenueUri($company, sprintf('/revenue/2026-%02d', $month)), ['lines' => ['services_commercial' => 90_000]])->assertOk();
    }
}

describe('saisie', function (): void {
    it('enregistre un mois en centimes, chiffré en base, et le relit', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-10'), ['lines' => ['services_commercial' => 412_050]])
            ->assertOk()
            ->assertJsonPath('data.period', '2026-10')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.lines', ['services_commercial' => 412_050])
            ->assertJsonPath('data.total', 412_050)
            ->assertJsonPath('disclaimer.version', 1);

        expect(DB::table('compliance_revenue_entries')->value('amount'))->not->toContain('412050');

        $this->actingAs($user)->getJson(revenueUri($company, '/revenue?year=2026'))
            ->assertOk()
            ->assertJsonPath('data.months.0', ['period' => '2026-10', 'lines' => ['services_commercial' => 412_050], 'total' => 412_050])
            ->assertJsonPath('data.total', 412_050);
    });

    it('remplace les montants du mois, et supprime un mois', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user, ['activity_types' => ['services_commercial', 'sales']]);

        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-10'), ['lines' => ['services_commercial' => 100, 'sales' => 200]])->assertOk();
        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-10'), ['lines' => ['sales' => 300]])
            ->assertOk()
            ->assertJsonPath('data.lines', ['sales' => 300]);

        $this->actingAs($user)->deleteJson(revenueUri($company, '/revenue/2026-10'))->assertNoContent();
        $this->actingAs($user)->getJson(revenueUri($company, '/revenue'))->assertJsonPath('data.total', 0);
    });

    it('refuse un montant qui n\'est pas un entier de centimes dans les bornes', function (mixed $amount): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-10'), ['lines' => ['services_commercial' => $amount]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.services_commercial');
    })->with([
        'chaîne' => ['100000'],
        'décimal' => [1000.5],
        'négatif' => [-1],
        'trop grand' => [100_000_000_000],
        'booléen' => [true],
        'nul' => [null],
    ]);

    it('refuse une activité que l\'entreprise n\'a pas déclarée', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-10'), ['lines' => ['sales' => 100]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
    });

    it('refuse un mois futur ou antérieur à la création', function (string $period): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->putJson(revenueUri($company, "/revenue/{$period}"), ['lines' => ['services_commercial' => 100]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
    })->with(['2026-12', '2026-02', '2015-01']);

    it('ne reconnaît pas un mois mal écrit', function (string $period): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->putJson(revenueUri($company, "/revenue/{$period}"), ['lines' => ['services_commercial' => 100]])->assertNotFound();
    })->with(['2026-13', '2026-1', 'octobre']);

    it('exporte le chiffre d\'affaires en CSV, montants exacts', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-10'), ['lines' => ['services_commercial' => 412_005]])->assertOk();

        $response = $this->actingAs($user)->get(revenueUri($company, '/revenue.csv?year=2026'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        expect($response->getContent())->toBe("period;activity_type;amount_cents;amount;currency\r\n2026-10;services_commercial;412005;4120.05;EUR\r\n")
            ->and(securityLog('compliance.export_downloaded'))->toHaveCount(1);
    });
});

describe('seuils', function (): void {
    it('calcule le seuil proratisé, le ratio et la projection en entiers arrondis par défaut', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        fillRevenue($company);

        $this->actingAs($user)->getJson(revenueUri($company, '/thresholds'))
            ->assertOk()
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.thresholds.0.code', 'fr.test.seuil')
            ->assertJsonPath('data.thresholds.0.threshold', ['amount' => 838_356, 'base_amount' => 1_000_000, 'prorated' => true, 'active_days' => 306])
            ->assertJsonPath('data.thresholds.0.revenue', 720_000)
            ->assertJsonPath('data.thresholds.0.ratio_basis_points', 8_588)
            ->assertJsonPath('data.thresholds.0.levels', [['level' => 80, 'reached' => true], ['level' => 100, 'reached' => false]])
            ->assertJsonPath('data.thresholds.0.projection', [
                'amount' => 899_265,
                'ratio_basis_points' => 10_726,
                'exceeds' => true,
                'covered_until' => '2026-10-31',
                'method' => 'linear',
            ])
            ->assertJsonPath('data.thresholds.0.rounding', 'floor')
            ->assertJsonPath('data.thresholds.0.consequence', 'Conséquence de test du dépassement.')
            ->assertJsonPath('data.thresholds.0.source.url', 'https://entreprendre.service-public.fr/vosdroits/F23961');
    });

    it('compte le mois en cours dans le cumul mais pas dans la projection', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        fillRevenue($company);
        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-11'), ['lines' => ['services_commercial' => 500_000]])->assertOk();

        $this->actingAs($user)->getJson(revenueUri($company, '/thresholds'))
            ->assertJsonPath('data.thresholds.0.revenue', 1_220_000)
            ->assertJsonPath('data.thresholds.0.projection.amount', 899_265);
    });

    it('ne projette rien avant la fin du premier mois complet', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user, ['created_on' => '2026-11-02']);
        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-11'), ['lines' => ['services_commercial' => 100]])->assertOk();

        $this->actingAs($user)->getJson(revenueUri($company, '/thresholds'))->assertJsonPath('data.thresholds.0.projection', null);
    });

    it('ignore les seuils en brouillon et ceux qui ne s\'appliquent pas au profil', function (): void {
        ComplianceRule::factory()->threshold('fr.test.brouillon', 500, ['services_commercial'])->create();
        ComplianceRule::factory()->verified()->threshold('fr.test.ventes', 500, ['sales'], false, ['activity_types' => ['sales']])->create();
        ComplianceRule::factory()->verified()->threshold('fr.test.tva-reel', 500, ['services_commercial'], false, ['vat_regimes' => ['simplified']])->create();
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $codes = collect($this->actingAs($user)->getJson(revenueUri($company, '/thresholds'))->json('data.thresholds'))->pluck('code')->all();

        expect($codes)->toBe(['fr.test.seuil']);
    });

    it('enregistre chaque palier atteint et le dépassement projeté une seule fois par année', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        fillRevenue($company);
        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-10'), ['lines' => ['services_commercial' => 90_000]])->assertOk();

        $alerts = ThresholdAlert::query()->orderBy('kind')->get();
        expect($alerts->map(fn (ThresholdAlert $alert): array => [$alert->kind, $alert->level, $alert->year])->all())
            ->toBe([['projected', 100, 2026], ['reached', 80, 2026]]);
    });
});

describe('estimation (premium)', function (): void {
    beforeEach(function (): void {
        // Taux fictifs : 21,20 % et 1,00 %, et un taux qui ne s'applique qu'avec une option.
        ComplianceRule::factory()->verified()->rate('fr.test.cotisations', 2_120, ['services_commercial'], 'social')->create();
        ComplianceRule::factory()->verified()->rate('fr.test.formation', 100, ['services_commercial'], 'training')->create();
        ComplianceRule::factory()->verified()->rate('fr.test.impot', 170, ['services_commercial'], 'tax', ['options' => ['income_tax_withholding' => true]])->create();
        ComplianceRule::factory()->rate('fr.test.brouillon', 9_999, ['services_commercial'])->create();
    });

    it('est réservée au premium (403 premium_required)', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->getJson(revenueUri($company, '/estimate'))
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('feature', 'estimate');
    });

    it('applique les seuls taux vérifiés qui s\'appliquent, en centimes arrondis par défaut', function (): void {
        $user = complianceUser();
        makePremium($user);
        $company = complianceCompanyOf($user);
        fillRevenue($company);

        $this->actingAs($user)->getJson(revenueUri($company, '/estimate'))
            ->assertOk()
            ->assertJsonPath('data.is_estimate', true)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.lines.0.code', 'fr.test.cotisations')
            ->assertJsonPath('data.lines.0.base', 720_000)
            ->assertJsonPath('data.lines.0.amount', 152_640)
            ->assertJsonPath('data.lines.1.amount', 7_200)
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.total', 159_840)
            ->assertJsonPath('data.rounding', 'floor');
    });

    it('n\'estime rien sans taux vérifié pour ce profil', function (): void {
        $user = complianceUser();
        makePremium($user);
        $company = complianceCompanyOf($user, ['activity_types' => ['sales']]);

        $this->actingAs($user)->getJson(revenueUri($company, '/estimate'))
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.lines', []);
    });
});

describe('confidentialité', function (): void {
    it('cache le chiffre d\'affaires d\'un autre utilisateur derrière un 404, admins compris', function (string $role): void {
        $company = complianceCompanyOf();
        $other = $role === 'moderateur' ? moderator() : complianceUser($role);
        makePremium($other);

        foreach ([
            ['GET', '/revenue'], ['GET', '/revenue.csv'], ['GET', '/thresholds'], ['GET', '/estimate'],
            ['PUT', '/revenue/2026-10'], ['DELETE', '/revenue/2026-10'],
        ] as [$method, $path]) {
            $this->actingAs($other)->json($method, revenueUri($company, $path), ['lines' => ['services_commercial' => 1]])->assertNotFound();
        }

        expect(DB::table('compliance_revenue_entries')->count())->toBe(0);
    })->with(['entrepreneur', 'prestataire', 'admin', 'moderateur']);

    it('n\'écrit jamais un montant dans le journal security', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        fillRevenue($company);
        $this->actingAs($user)->get(revenueUri($company, '/revenue.csv'))->assertOk();

        expect(json_encode(array_map(fn ($record) => $record->context, securityLog())))->not->toContain('90000')->not->toContain('720000');
    });

    it('limite la saisie du chiffre d\'affaires (429)', function (): void {
        config(['security.rate_limits.compliance_revenue.per_hour' => 1]);
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-10'), ['lines' => ['services_commercial' => 1]])->assertOk();
        $this->actingAs($user)->putJson(revenueUri($company, '/revenue/2026-09'), ['lines' => ['services_commercial' => 1]])->assertTooManyRequests();
    });
});
