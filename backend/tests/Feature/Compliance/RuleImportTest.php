<?php

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleContent;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Import des règles en brouillon (compliance:import-rules) et garde-fous sur le fichier livré
| database/data/compliance/fr-2027.draft.json : toute valeur remplie cite la page officielle
| lue, tout manque est signalé « à compléter », rien n'est visible avant vérification.
*/

const FR_2027_DRAFTS = 'database/data/compliance/fr-2027.draft.json';

beforeEach(function (): void {
    Carbon::setTestNow('2027-02-01 10:00:00');
    Notification::fake();
    $this->seed(ServiceCategorySeeder::class);
});

/**
 * @return list<array<string, mixed>>
 */
function shippedDraftRules(): array
{
    return json_decode((string) file_get_contents(base_path(FR_2027_DRAFTS)), true)['rules'];
}

/**
 * Écrit un fichier de règles temporaire et renvoie son chemin.
 *
 * @param  list<array<string, mixed>>  $rules
 */
function rulesFile(array $rules, string $country = 'FR', string $format = 'loopartner.compliance.rules'): string
{
    $path = tempnam(sys_get_temp_dir(), 'rules').'.json';
    file_put_contents($path, json_encode(['format' => $format, 'version' => 1, 'country' => $country, 'rules' => $rules]));

    return $path;
}

describe('fichier livré', function (): void {
    it('s\'importe entièrement en brouillons, invisibles des utilisateurs', function (): void {
        $this->artisan('compliance:import-rules', ['path' => FR_2027_DRAFTS])->assertSuccessful();

        expect(ComplianceRule::query()->count())->toBe(count(shippedDraftRules()))
            ->and(ComplianceRule::query()->where('status', '!=', RuleStatus::Draft->value)->count())->toBe(0)
            ->and(ComplianceRule::query()->where('is_demo', true)->count())->toBe(0)
            ->and(securityLog('compliance.rules_imported'))->toHaveCount(1);

        $this->actingAs(complianceUser())->getJson('/api/v1/compliance/countries')->assertOk()->assertJsonPath('data', []);
    });

    it('ne contient aucun montant ni taux rempli : ils restent à compléter pour 2027', function (): void {
        foreach (shippedDraftRules() as $rule) {
            expect($rule['payload']['amount'] ?? null)->toBeNull("{$rule['code']} : montant")
                ->and($rule['payload']['basis_points'] ?? null)->toBeNull("{$rule['code']} : taux");
        }
    });

    it('cite la page officielle lue pour chaque date remplie, et signale chaque manque', function (): void {
        foreach (shippedDraftRules() as $rule) {
            $notes = (string) ($rule['internal_notes'] ?? '');
            $schedule = $rule['payload']['schedule'] ?? [];
            $filled = array_filter(
                [$schedule['month'] ?? null, $schedule['day'] ?? null, $schedule['days'] ?? null, $schedule['offset_months'] ?? null, $schedule['months'] ?? null, $schedule['dates'] ?? null],
                fn (mixed $value): bool => $value !== null && $value !== [],
            );

            if ($filled !== []) {
                expect($notes)->toMatch('/lue? sur https:\/\/[^ ]+ le 2026-11-16/u', "{$rule['code']} : date remplie sans source lue");
                expect($rule['source_url'])->toStartWith('https://', "{$rule['code']} : source absente");
            }

            $model = new ComplianceRule;
            $model->forceFill([...RuleContent::normalize(RuleKind::from($rule['kind']), $rule), 'kind' => $rule['kind']]);

            if (RuleContent::missingFields($model) !== []) {
                expect(str_contains($notes, 'à compléter'))->toBeTrue("{$rule['code']} : manque non signalé");
            }
        }
    });

    it('ne peut pas être vérifié en l\'état tant qu\'il manque des valeurs', function (): void {
        $this->artisan('compliance:import-rules', ['path' => FR_2027_DRAFTS])->assertSuccessful();
        $rule = ComplianceRule::query()->where('code', 'fr.micro.ceiling.services')->sole();

        asModerator()->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", [
            'version' => 1,
            'verification_note' => 'Vérifié par le cabinet Exemple le 2027-01-10 sur la page F32353.',
        ])->assertUnprocessable()->assertJsonValidationErrors('rule');
    });

    it('n\'est jamais chargé par ReferenceDataSeeder', function (): void {
        $this->seed(ReferenceDataSeeder::class);

        expect(ComplianceRule::query()->count())->toBe(0)
            ->and((string) file_get_contents(base_path('database/seeders/ReferenceDataSeeder.php')))->not->toContain('compliance');
    });
});

describe('commande d\'import', function (): void {
    it('ignore une règle déjà présente et ne modifie jamais une règle vérifiée', function (): void {
        $verified = ComplianceRule::factory()->verified()->threshold('fr.micro.ceiling.sales', 123)->create(['valid_from' => '2027-01-01']);

        $this->artisan('compliance:import-rules', ['path' => FR_2027_DRAFTS])
            ->expectsOutputToContain('déjà présente, ignorée : fr.micro.ceiling.sales')
            ->assertSuccessful();
        $this->artisan('compliance:import-rules', ['path' => FR_2027_DRAFTS])->assertSuccessful();

        expect($verified->fresh()->status)->toBe(RuleStatus::Verified)
            ->and($verified->fresh()->payload['amount'])->toBe(123)
            ->and(ComplianceRule::query()->count())->toBe(count(shippedDraftRules()));
    });

    it('n\'écrit rien en simulation', function (): void {
        $this->artisan('compliance:import-rules', ['path' => FR_2027_DRAFTS, '--dry-run' => true])
            ->expectsOutputToContain('[simulation]')
            ->assertSuccessful();

        expect(ComplianceRule::query()->count())->toBe(0);
    });

    it('n\'importe rien si une seule règle est invalide', function (array $invalid): void {
        $rules = [shippedDraftRules()[0], [...shippedDraftRules()[5], ...$invalid]];

        $this->artisan('compliance:import-rules', ['path' => rulesFile($rules)])
            ->expectsOutputToContain('aucune règle importée')
            ->assertFailed();

        expect(ComplianceRule::query()->count())->toBe(0);
    })->with([
        'montant en texte' => [['payload' => ['amount' => '83 600', 'activity_types' => ['sales']]]],
        'statut imposé' => [['status' => 'verified']],
        'règle de démonstration' => [['is_demo' => true]],
        'code d\'un autre pays' => [['code' => 'be.micro.ceiling']],
        'HTML' => [['title' => ['fr' => '<b>Plafond</b>', 'en' => null]]],
    ]);

    it('refuse un fichier d\'un format inconnu ou d\'un pays non pris en charge', function (string $path): void {
        $this->artisan('compliance:import-rules', ['path' => $path])->assertFailed();

        expect(ComplianceRule::query()->count())->toBe(0);
    })->with([
        'format' => fn () => rulesFile([shippedDraftRules()[0]], 'FR', 'autre'),
        'pays' => fn () => rulesFile([shippedDraftRules()[0]], 'DE'),
        'fichier absent' => fn () => base_path('database/data/compliance/absent.json'),
        'pas du JSON' => fn () => base_path('composer.lock.txt'),
    ]);
});
