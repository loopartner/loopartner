<?php

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\Journey;
use App\Modules\Compliance\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Échéances, tâches et parcours d'une entreprise : échéances personnelles, état, tâches issues
| des règles ou personnelles, parcours types (gratuit : un parcours actif), données privées.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
    verifiedLegalForm();
    serviceCategory('tenue-comptable', 'Tenue comptable');
    verifiedDeadlineRule('fr.test.cfe', ['type' => 'yearly', 'month' => 12, 'day' => 15], [], [
        ['key' => 'verifier-avis', 'title' => ['fr' => 'Vérifier l\'avis', 'en' => 'Check the notice'], 'service_category' => 'tenue-comptable'],
    ], ['summary' => ['fr' => 'Résumé de test', 'en' => null]]);
});

function firstRuleDeadline(Company $company): Deadline
{
    return Deadline::query()->where('company_id', $company->id)->where('source', 'rule')->orderBy('due_on')->firstOrFail();
}

function companyUri(Company $company, string $path = ''): string
{
    return "/api/v1/me/compliance/companies/{$company->uuid}".$path;
}

describe('calendrier', function (): void {
    it('liste les échéances de toutes ses entreprises sur une période, avec la source de la règle', function (): void {
        $user = complianceUser();
        makePremium($user);
        complianceCompanyOf($user);
        complianceCompanyOf($user, ['name' => 'Seconde']);
        complianceCompanyOf();

        $response = $this->actingAs($user)->getJson('/api/v1/me/compliance/deadlines?from=2026-11-01&to=2027-01-31')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Échéance fr.test.cfe')
            ->assertJsonPath('data.0.summary', 'Résumé de test')
            ->assertJsonPath('data.0.due_on', '2026-12-15')
            ->assertJsonPath('data.0.rule.source.url', 'https://entreprendre.service-public.fr/vosdroits/F23961')
            ->assertJsonPath('data.0.tasks.0.title', 'Vérifier l\'avis')
            ->assertJsonPath('data.0.tasks.0.service_category.slug', 'tenue-comptable')
            ->assertJsonPath('disclaimer.version', 1);

        expect(collect($response->json('data'))->pluck('company.name')->sort()->values()->all())->toBe(['Atelier Camille', 'Seconde']);
        expectNoPrivateFields($response->json());
    });

    it('traduit le titre selon la langue demandée', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->withHeader('Accept-Language', 'en')->getJson(companyUri($company, '/deadlines'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Deadline fr.test.cfe')
            ->assertJsonPath('data.0.tasks.0.title', 'Check the notice');
    });

    it('filtre les échéances en retard', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        $this->travelTo(Carbon::parse('2026-12-20 10:00:00'));

        $this->actingAs($user)->getJson(companyUri($company, '/deadlines?status=overdue&from=2026-12-01&to=2026-12-31'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.overdue', true);
    });

    it('refuse une période trop longue ou inversée', function (string $query): void {
        $user = complianceUser();
        complianceCompanyOf($user);

        $this->actingAs($user)->getJson('/api/v1/me/compliance/deadlines?'.$query)->assertUnprocessable();
    })->with(['from=2026-01-01&to=2028-01-01', 'from=2027-01-01&to=2026-01-01', 'from=hier', 'status=urgent', 'company=pas-un-uuid']);
});

describe('échéances', function (): void {
    it('ajoute, modifie, coche et supprime une échéance personnelle, chiffrée en base', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $uuid = $this->actingAs($user)->postJson(companyUri($company, '/deadlines'), ['title' => 'Rendez-vous banque', 'due_on' => '2027-01-10', 'notes' => 'Apporter le bilan'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'custom')
            ->assertJsonPath('data.rule', null)
            ->json('data.uuid');

        $row = DB::table('compliance_deadlines')->where('uuid', $uuid)->first();
        expect($row->title)->not->toContain('banque')->and($row->notes)->not->toContain('bilan');

        $this->actingAs($user)->patchJson(companyUri($company, "/deadlines/{$uuid}"), ['due_on' => '2027-01-12'])->assertOk()->assertJsonPath('data.due_on', '2027-01-12');
        $this->actingAs($user)->postJson(companyUri($company, "/deadlines/{$uuid}/complete"))->assertOk()->assertJsonPath('data.status', 'done');
        $this->actingAs($user)->postJson(companyUri($company, "/deadlines/{$uuid}/complete"))->assertConflict();
        $this->actingAs($user)->postJson(companyUri($company, "/deadlines/{$uuid}/reopen"))->assertOk()->assertJsonPath('data.status', 'todo');
        $this->actingAs($user)->deleteJson(companyUri($company, "/deadlines/{$uuid}"))->assertNoContent();
    });

    it('ne laisse modifier que les notes d\'une échéance issue d\'une règle, et ne la supprime pas', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        $deadline = firstRuleDeadline($company);
        $uri = companyUri($company, "/deadlines/{$deadline->uuid}");

        $this->actingAs($user)->patchJson($uri, ['notes' => 'Payé par prélèvement'])->assertOk()->assertJsonPath('data.notes', 'Payé par prélèvement');
        $this->actingAs($user)->patchJson($uri, ['due_on' => '2027-03-01'])->assertUnprocessable()->assertJsonValidationErrors('due_on');
        $this->actingAs($user)->deleteJson($uri)->assertConflict();
        $this->actingAs($user)->postJson("{$uri}/skip")->assertOk()->assertJsonPath('data.status', 'skipped');
    });

    it('refuse les champs fixés par la plateforme et les dates hors bornes', function (array $payload, string $error): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->postJson(companyUri($company, '/deadlines'), [...['title' => 'Mon échéance', 'due_on' => '2027-01-10'], ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);
    })->with([
        'statut' => [['status' => 'done'], 'status'],
        'règle' => [['rule_code' => 'fr.test.cfe'], 'rule_code'],
        'trop loin' => [['due_on' => '2040-01-01'], 'due_on'],
        'HTML' => [['title' => '<b>gras</b>'], 'title'],
        'caractère invisible' => [['title' => "Mon\u{202E}échéance"], 'title'],
    ]);

    it('plafonne les échéances personnelles à venir (409)', function (): void {
        config(['compliance.max_custom_deadlines' => 1]);
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->postJson(companyUri($company, '/deadlines'), ['title' => 'Une', 'due_on' => '2027-01-10'])->assertCreated();
        $this->actingAs($user)->postJson(companyUri($company, '/deadlines'), ['title' => 'Deux', 'due_on' => '2027-01-11'])->assertConflict();
    });
});

describe('tâches', function (): void {
    it('ajoute une tâche personnelle sur une échéance, la coche et la supprime', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        $deadline = firstRuleDeadline($company);

        $uuid = $this->actingAs($user)->postJson(companyUri($company, '/tasks'), ['title' => 'Appeler le comptable', 'deadline' => $deadline->uuid, 'service_category' => 'tenue-comptable'])
            ->assertCreated()
            ->assertJsonPath('data.deadline', $deadline->uuid)
            ->assertJsonPath('data.service_category.name', 'Tenue comptable')
            ->json('data.uuid');

        $this->actingAs($user)->postJson(companyUri($company, "/tasks/{$uuid}/complete"))->assertOk()->assertJsonPath('data.completed', true);
        $this->actingAs($user)->patchJson(companyUri($company, "/tasks/{$uuid}"), ['title' => 'Appeler le cabinet'])->assertOk()->assertJsonPath('data.title', 'Appeler le cabinet');
        $this->actingAs($user)->deleteJson(companyUri($company, "/tasks/{$uuid}"))->assertNoContent();
    });

    it('liste les tâches libres de l\'entreprise', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        $this->actingAs($user)->postJson(companyUri($company, '/tasks'), ['title' => 'Ouvrir un compte pro'])->assertCreated();

        $this->actingAs($user)->getJson(companyUri($company, '/tasks'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.deadline', null);
    });

    it('ne modifie ni ne supprime une tâche issue d\'une règle, mais la coche', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        $task = Task::query()->where('company_id', $company->id)->where('source', 'rule')->firstOrFail();

        $this->actingAs($user)->patchJson(companyUri($company, "/tasks/{$task->uuid}"), ['title' => 'Autre'])->assertUnprocessable();
        $this->actingAs($user)->deleteJson(companyUri($company, "/tasks/{$task->uuid}"))->assertConflict();
        $this->actingAs($user)->postJson(companyUri($company, "/tasks/{$task->uuid}/complete"))->assertOk();
    });

    it('refuse une échéance ou une tâche d\'une autre entreprise du même utilisateur', function (): void {
        $user = complianceUser();
        makePremium($user);
        $first = complianceCompanyOf($user);
        $second = complianceCompanyOf($user, ['name' => 'Seconde']);
        $deadline = firstRuleDeadline($first);
        $task = Task::query()->where('company_id', $first->id)->firstOrFail();

        $this->actingAs($user)->postJson(companyUri($second, '/tasks'), ['title' => 'Tâche', 'deadline' => $deadline->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('deadline');
        $this->actingAs($user)->getJson(companyUri($second, "/deadlines/{$deadline->uuid}"))->assertNotFound();
        $this->actingAs($user)->postJson(companyUri($second, "/tasks/{$task->uuid}/complete"))->assertNotFound();
    });

    it('refuse une catégorie de services inconnue', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->postJson(companyUri($company, '/tasks'), ['title' => 'Tâche', 'service_category' => 'inconnue'])
            ->assertUnprocessable()->assertJsonValidationErrors('service_category');
    });
});

describe('parcours', function (): void {
    beforeEach(function (): void {
        ComplianceRule::factory()->verified()->journey('fr.journey.creer', [
            ['key' => 'statut', 'title' => ['fr' => 'Choisir son statut', 'en' => null], 'description' => ['fr' => 'Comparer les statuts.', 'en' => null], 'service_category' => 'tenue-comptable', 'source_url' => null],
            ['key' => 'immatriculer', 'title' => ['fr' => 'Immatriculer l\'entreprise', 'en' => null], 'description' => null, 'service_category' => null, 'source_url' => null],
        ])->create();
        ComplianceRule::factory()->verified()->journey('fr.journey.cloturer', [
            ['key' => 'inventaire', 'title' => ['fr' => 'Faire l\'inventaire', 'en' => null], 'description' => null, 'service_category' => null, 'source_url' => null],
        ])->create();
        ComplianceRule::factory()->journey('fr.journey.brouillon', [])->create();
    });

    it('liste les parcours vérifiés du pays', function (): void {
        $this->actingAs(complianceUser())->getJson('/api/v1/compliance/countries/FR/journeys')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'fr.journey.cloturer');
    });

    it('démarre un parcours, et le termine quand toutes ses étapes sont cochées', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $response = $this->actingAs($user)->postJson(companyUri($company, '/journeys'), ['journey' => 'fr.journey.creer'])
            ->assertCreated()
            ->assertJsonPath('data.progress', ['done' => 0, 'total' => 2])
            ->assertJsonPath('data.steps.0.title', 'Choisir son statut')
            ->assertJsonPath('data.steps.0.description', 'Comparer les statuts.')
            ->assertJsonPath('data.steps.0.service_category.slug', 'tenue-comptable');

        foreach ($response->json('data.steps') as $step) {
            $this->actingAs($user)->postJson(companyUri($company, "/tasks/{$step['uuid']}/complete"))->assertOk();
        }

        $journey = Journey::query()->sole();
        expect($journey->completed_at)->not->toBeNull();

        $this->actingAs($user)->postJson(companyUri($company, "/tasks/{$response->json('data.steps.0.uuid')}/reopen"))->assertOk();
        expect($journey->fresh()->completed_at)->toBeNull();
    });

    it('limite le gratuit à un parcours actif (403 premium_required), et refuse un parcours en double (409)', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->postJson(companyUri($company, '/journeys'), ['journey' => 'fr.journey.creer'])->assertCreated();
        $this->actingAs($user)->postJson(companyUri($company, '/journeys'), ['journey' => 'fr.journey.creer'])->assertConflict();
        $this->actingAs($user)->postJson(companyUri($company, '/journeys'), ['journey' => 'fr.journey.cloturer'])
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('feature', 'multiple_journeys');

        makePremium($user);
        $this->actingAs($user)->postJson(companyUri($company, '/journeys'), ['journey' => 'fr.journey.cloturer'])->assertCreated();
    });

    it('refuse un parcours en brouillon ou inconnu, et supprime un parcours suivi', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);

        $this->actingAs($user)->postJson(companyUri($company, '/journeys'), ['journey' => 'fr.journey.brouillon'])->assertUnprocessable();
        $uuid = $this->actingAs($user)->postJson(companyUri($company, '/journeys'), ['journey' => 'fr.journey.creer'])->json('data.uuid');

        $this->actingAs($user)->deleteJson(companyUri($company, "/journeys/{$uuid}"))->assertNoContent();
        expect(Task::query()->whereNotNull('journey_id')->count())->toBe(0);
    });
});

describe('confidentialité et premium', function (): void {
    it('cache les échéances, tâches et parcours d\'un autre utilisateur derrière un 404, admins compris', function (string $role): void {
        $company = complianceCompanyOf();
        $deadline = firstRuleDeadline($company);
        $task = Task::query()->where('company_id', $company->id)->firstOrFail();
        $other = $role === 'moderateur' ? moderator() : complianceUser($role);

        foreach ([
            ['GET', companyUri($company, '/deadlines')],
            ['GET', companyUri($company, "/deadlines/{$deadline->uuid}")],
            ['PATCH', companyUri($company, "/deadlines/{$deadline->uuid}")],
            ['POST', companyUri($company, "/deadlines/{$deadline->uuid}/skip")],
            ['POST', companyUri($company, '/deadlines')],
            ['GET', companyUri($company, '/tasks')],
            ['POST', companyUri($company, "/tasks/{$task->uuid}/complete")],
            ['GET', companyUri($company, '/journeys')],
        ] as [$method, $uri]) {
            $this->actingAs($other)->json($method, $uri, ['notes' => 'x', 'title' => 'x', 'due_on' => '2027-01-01'])->assertNotFound();
        }

        expect($deadline->fresh()->status->value)->toBe('todo');
    })->with(['entrepreneur', 'prestataire', 'particulier', 'admin', 'moderateur']);

    it('n\'affiche jamais l\'échéance d\'un autre utilisateur dans le calendrier global', function (): void {
        $user = complianceUser();
        $victim = complianceCompanyOf();

        $this->actingAs($user)->getJson("/api/v1/me/compliance/deadlines?company={$victim->uuid}")->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuse toute écriture sur une entreprise verrouillée après la fin du premium', function (): void {
        $user = complianceUser();
        $grant = makePremium($user);
        complianceCompanyOf($user);
        $locked = complianceCompanyOf($user, ['name' => 'Seconde']);
        $grant->forceFill(['ends_at' => now()->subMinute()])->save();
        $deadline = firstRuleDeadline($locked);

        $this->actingAs($user)->postJson(companyUri($locked, "/deadlines/{$deadline->uuid}/complete"))->assertForbidden()->assertJsonPath('code', 'premium_required');
        $this->actingAs($user)->postJson(companyUri($locked, '/tasks'), ['title' => 'Tâche'])->assertForbidden();
        $this->actingAs($user)->getJson(companyUri($locked, '/deadlines'))->assertOk();
    });
});
