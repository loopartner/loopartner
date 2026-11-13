<?php

use App\Models\User;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Deadline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Questionnaire de démarrage et entreprises (/compliance/countries, /me/compliance/companies) :
| règles vérifiées seulement, données privées (404 pour tout autre utilisateur), limites
| gratuit et premium.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
});

describe('questionnaire', function (): void {
    it('ne propose qu\'un pays avec un statut vérifié pour toute l\'année en cours', function (): void {
        $this->actingAs(complianceUser())->getJson('/api/v1/compliance/countries')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('disclaimer.version', 1);

        ComplianceRule::factory()->legalForm('fr.legal_form.brouillon')->create();
        $this->actingAs(complianceUser())->getJson('/api/v1/compliance/countries')->assertJsonPath('data', []);

        verifiedLegalForm();
        $this->actingAs(complianceUser())->getJson('/api/v1/compliance/countries')
            ->assertJsonPath('data', [['code' => 'FR', 'currency' => 'EUR']]);
    });

    it('ne montre jamais une règle en brouillon, retirée ou de démonstration', function (): void {
        verifiedLegalForm();
        ComplianceRule::factory()->legalForm('fr.legal_form.brouillon')->create();
        ComplianceRule::factory()->retired()->legalForm('fr.legal_form.retiree')->create();
        ComplianceRule::factory()->verified()->demo()->legalForm('fr.legal_form.demo')->create();

        $this->actingAs(complianceUser('prestataire'))->getJson('/api/v1/compliance/countries/fr/questionnaire')
            ->assertOk()
            ->assertJsonCount(1, 'data.legal_forms')
            ->assertJsonPath('data.legal_forms.0.code', 'fr.legal_form.micro')
            ->assertJsonPath('data.legal_forms.0.options.1.key', 'income_tax_withholding')
            ->assertJsonPath('data.legal_forms.0.options.0', ['key' => 'declaration_frequency', 'label' => 'Périodicité des déclarations de chiffre d\'affaires', 'type' => 'choice', 'choices' => ['monthly', 'quarterly'], 'default' => 'quarterly'])
            ->assertJsonPath('data.legal_forms.0.source.url', 'https://entreprendre.service-public.fr/vosdroits/F23961')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonStructure(['disclaimer' => ['text', 'version']]);
    });

    it('répond 404 pour un pays sans règles vérifiées', function (): void {
        verifiedLegalForm();

        $this->actingAs(complianceUser())->getJson('/api/v1/compliance/countries/BE/questionnaire')->assertNotFound();
    });

    it('réserve le questionnaire aux prestataires et entrepreneurs avec un email vérifié', function (): void {
        verifiedLegalForm();

        foreach (['particulier', 'redacteur', 'admin'] as $role) {
            $this->actingAs(complianceUser($role))->getJson('/api/v1/compliance/countries')->assertForbidden();
        }

        $this->actingAs(complianceUser('entrepreneur', verified: false))->getJson('/api/v1/compliance/countries')->assertForbidden();
    });
});

describe('création', function (): void {
    it('crée l\'entreprise et son calendrier à partir des règles vérifiées', function (): void {
        verifiedLegalForm();
        verifiedDeadlineRule('fr.test.cfe', ['type' => 'yearly', 'month' => 12, 'day' => 15]);
        ComplianceRule::factory()->deadline('fr.test.brouillon', ['type' => 'yearly', 'month' => 12, 'day' => 1])->create();
        $user = complianceUser();

        $uuid = $this->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Atelier Camille')
            ->assertJsonPath('data.legal_form', ['code' => 'fr.legal_form.micro', 'title' => 'Statut fr.legal_form.micro'])
            ->assertJsonPath('data.options', ['declaration_frequency' => 'quarterly', 'income_tax_withholding' => false])
            ->assertJsonPath('data.registration_number', '12345678900012')
            ->assertJsonPath('data.locked', false)
            ->assertJsonPath('data.rules_status', 'ok')
            ->assertJsonPath('disclaimer.version', 1)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.user_id')
            ->json('data.uuid');

        $company = Company::query()->where('uuid', $uuid)->sole();
        expect(Deadline::query()->where('company_id', $company->id)->pluck('rule_code')->unique()->values()->all())->toBe(['fr.test.cfe'])
            ->and(securityLog('compliance.company_created'))->toHaveCount(1);
    });

    it('chiffre en base le nom et les numéros de l\'entreprise', function (): void {
        verifiedLegalForm();
        $company = complianceCompanyOf(null, ['vat_number' => 'FR 12 123456789']);

        $row = DB::table('compliance_companies')->where('id', $company->id)->first();
        expect($row->name)->not->toContain('Camille')
            ->and($row->registration_number)->not->toContain('123456789')
            ->and($row->vat_number)->not->toContain('123456789')
            ->and($company->fresh()->vat_number)->toBe('FR12123456789');
    });

    it('refuse un statut, une activité, un régime ou une option que les règles ne proposent pas', function (array $overrides, string $error): void {
        verifiedLegalForm('fr.legal_form.micro', ['declaration_frequency']);
        ComplianceRule::factory()->legalForm('fr.legal_form.sas')->create();

        $this->actingAs(complianceUser())->postJson('/api/v1/me/compliance/companies', companyPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);

        expect(Company::query()->count())->toBe(0);
    })->with([
        'statut en brouillon' => [['legal_form' => 'fr.legal_form.sas'], 'legal_form'],
        'statut inconnu' => [['legal_form' => 'fr.legal_form.inconnu'], 'legal_form'],
        'pays non disponible' => [['country' => 'BE'], 'country'],
        'activité refusée par le statut' => [['activity_types' => ['liberal_cipav']], 'activity_types'],
        'régime refusé par le statut' => [['vat_regime' => 'normal'], 'vat_regime'],
        'option non proposée' => [['options' => ['income_tax_withholding' => true]], 'options.income_tax_withholding'],
        'valeur d\'option invalide' => [['options' => ['declaration_frequency' => 'yearly']], 'options.declaration_frequency'],
        'option inconnue' => [['options' => ['crypto' => true]], 'options'],
        'trois activités' => [['activity_types' => ['sales', 'services_commercial', 'services_liberal']], 'activity_types'],
        'SIRET trop court' => [['registration_number' => '1234'], 'registration_number'],
        'TVA mal formée' => [['vat_number' => 'DE123'], 'vat_number'],
        'date de création trop lointaine' => [['created_on' => '2030-01-01'], 'created_on'],
        'clôture inexistante' => [['fiscal_year_end' => '02-30'], 'fiscal_year_end'],
        'HTML dans le nom' => [['name' => '<img src=x onerror=alert(1)>'], 'name'],
        'propriétaire imposé' => [['user_id' => 1], 'user_id'],
        'uuid imposé' => [['uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'], 'uuid'],
    ]);

    it('limite le gratuit à une entreprise (403 premium_required) et le premium à cinq (409)', function (): void {
        verifiedLegalForm();
        $user = complianceUser();
        complianceCompanyOf($user);

        $this->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload(['name' => 'Deuxième']))
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('feature', 'multiple_companies');
        expect(securityLog('compliance.premium_required'))->toHaveCount(1);

        makePremium($user);
        foreach (range(2, 5) as $index) {
            $this->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload(['name' => "Entreprise {$index}"]))->assertCreated();
        }

        $this->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload(['name' => 'Sixième']))->assertConflict();
        expect(Company::query()->where('user_id', $user->id)->count())->toBe(5);
    });

    it('limite les créations d\'entreprises par jour (429)', function (): void {
        config(['security.rate_limits.compliance_companies.per_day' => 1]);
        verifiedLegalForm();
        $user = complianceUser();
        makePremium($user);

        $this->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload())->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/me/compliance/companies', companyPayload())->assertTooManyRequests();
    });
});

describe('lecture, modification, suppression', function (): void {
    it('cache l\'entreprise d\'un autre utilisateur derrière un 404, admins compris', function (string $role): void {
        verifiedLegalForm();
        $company = complianceCompanyOf();
        $other = complianceUser($role);
        $uri = "/api/v1/me/compliance/companies/{$company->uuid}";

        $this->actingAs($other)->getJson($uri)->assertNotFound();
        $this->actingAs($other)->patchJson($uri, ['name' => 'Piratée'])->assertNotFound();
        $this->actingAs($other)->postJson("{$uri}/activate")->assertNotFound();
        fromFrontend()->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])->deleteJson($uri)->assertNotFound();

        expect($company->fresh()->name)->toBe('Atelier Camille');
    })->with(['entrepreneur', 'prestataire', 'particulier', 'redacteur', 'admin']);

    it('ne montre à l\'admin modérateur aucune entreprise', function (): void {
        verifiedLegalForm();
        $company = complianceCompanyOf();

        asModerator()->getJson("/api/v1/me/compliance/companies/{$company->uuid}")->assertNotFound();
        asModerator()->getJson('/api/v1/me/compliance/companies')->assertForbidden();
    });

    it('liste uniquement ses entreprises', function (): void {
        verifiedLegalForm();
        $user = complianceUser('prestataire');
        complianceCompanyOf($user);
        complianceCompanyOf();

        $this->actingAs($user)->getJson('/api/v1/me/compliance/companies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('disclaimer.version', 1);
    });

    it('modifie le profil et recalcule les échéances futures', function (): void {
        verifiedLegalForm();
        verifiedDeadlineRule('fr.test.tva', ['type' => 'yearly', 'month' => 5, 'day' => 5], ['vat_regimes' => ['simplified']]);
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        expect(Deadline::query()->where('company_id', $company->id)->count())->toBe(0);

        $this->actingAs($user)->patchJson("/api/v1/me/compliance/companies/{$company->uuid}", ['vat_regime' => 'simplified'])
            ->assertOk()
            ->assertJsonPath('data.vat_regime', 'simplified');

        expect(Deadline::query()->where('company_id', $company->id)->pluck('due_on')->map->toDateString()->all())->toBe(['2027-05-05']);
    });

    it('refuse de changer de pays', function (): void {
        verifiedLegalForm();
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->patchJson("/api/v1/me/compliance/companies/{$company->uuid}", ['country' => 'BE'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country');
    });

    it('exige une confirmation récente du mot de passe pour supprimer une entreprise (423)', function (): void {
        verifiedLegalForm();
        verifiedDeadlineRule('fr.test.cfe', ['type' => 'yearly', 'month' => 12, 'day' => 15]);
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        fromFrontend()->actingAs($user)->deleteJson("/api/v1/me/compliance/companies/{$company->uuid}")->assertStatus(423);
        expect(Company::query()->count())->toBe(1);

        fromFrontend()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->deleteJson("/api/v1/me/compliance/companies/{$company->uuid}")
            ->assertNoContent();

        expect(Company::query()->count())->toBe(0)
            ->and(Deadline::query()->count())->toBe(0)
            ->and(securityLog('compliance.company_deleted'))->toHaveCount(1);
    });
});

describe('fin du premium', function (): void {
    it('verrouille les entreprises en trop sans rien supprimer, et laisse choisir l\'entreprise active', function (): void {
        verifiedLegalForm();
        $user = complianceUser();
        $grant = makePremium($user);
        $first = complianceCompanyOf($user);
        $second = complianceCompanyOf($user, ['name' => 'Seconde']);

        $grant->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->actingAs($user)->getJson("/api/v1/me/compliance/companies/{$second->uuid}")->assertOk()->assertJsonPath('data.locked', true);
        $this->actingAs($user)->getJson("/api/v1/me/compliance/companies/{$first->uuid}")->assertOk()->assertJsonPath('data.locked', false);
        $this->actingAs($user)->patchJson("/api/v1/me/compliance/companies/{$second->uuid}", ['name' => 'Renommée'])
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required');

        $this->actingAs($user)->postJson("/api/v1/me/compliance/companies/{$second->uuid}/activate")->assertOk()->assertJsonPath('data.locked', false);
        $this->actingAs($user)->getJson("/api/v1/me/compliance/companies/{$first->uuid}")->assertJsonPath('data.locked', true);
        $this->actingAs($user)->patchJson("/api/v1/me/compliance/companies/{$second->uuid}", ['name' => 'Renommée'])->assertOk();

        expect(Company::query()->where('user_id', $user->id)->count())->toBe(2);
    });

    it('n\'a rien à activer avec premium ou une seule entreprise (409)', function (): void {
        verifiedLegalForm();
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->postJson("/api/v1/me/compliance/companies/{$company->uuid}/activate")->assertConflict();
    });

    it('présente l\'offre et les limites dans la vue d\'ensemble', function (): void {
        $user = complianceUser();

        $this->actingAs($user)->getJson('/api/v1/me/compliance')
            ->assertOk()
            ->assertJsonPath('data.premium', false)
            ->assertJsonPath('data.companies', ['count' => 0, 'max' => 1]);

        makePremium($user);
        $this->actingAs($user)->getJson('/api/v1/me/compliance')->assertJsonPath('data.companies.max', 5);
    });
});

it('signale une entreprise dont le statut n\'a plus de règle vérifiée', function (): void {
    $rule = verifiedLegalForm();
    $user = complianceUser();
    $company = complianceCompanyOf($user);

    $rule->forceFill(['status' => 'retired'])->save();

    $this->actingAs($user)->getJson("/api/v1/me/compliance/companies/{$company->uuid}")
        ->assertOk()
        ->assertJsonPath('data.rules_status', 'outdated');
});

it('refuse un utilisateur sans email vérifié', function (): void {
    $user = User::factory()->unverified()->create();
    $user->assignRole('entrepreneur');

    $this->actingAs($user)->getJson('/api/v1/me/compliance/companies')->assertForbidden();
});
