<?php

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Administration des règles fiscales et sociales (/admin/compliance/rules) : admins avec 2FA de
| session ; brouillon, vérification avec note obligatoire, nouvelle version, retrait, contrôle
| de version, journal d'audit.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
});

/**
 * Données valides d'une règle (seuil fictif de test, en brouillon).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function complianceRulePayload(array $overrides = []): array
{
    return [
        'country' => 'FR',
        'kind' => 'threshold',
        'code' => 'fr.test.seuil-services',
        'valid_from' => '2027-01-01',
        'valid_until' => '2027-12-31',
        'title' => ['fr' => 'Seuil de test des services', 'en' => 'Test services threshold'],
        'summary' => ['fr' => 'Résumé de test.', 'en' => null],
        'conditions' => ['legal_forms' => ['fr.legal_form.micro'], 'activity_types' => ['services_commercial'], 'options' => ['income_tax_withholding' => true]],
        'payload' => [
            'amount' => 1_000_000,
            'activity_types' => ['services_commercial'],
            'prorate_first_year' => true,
            'consequence' => ['fr' => 'Texte de test de la conséquence.', 'en' => null],
        ],
        'source_url' => 'https://www.impots.gouv.fr/une-page-de-test',
        'source_label' => 'Page de test',
        'internal_notes' => 'à compléter : rien, règle de test',
        ...$overrides,
    ];
}

function createdRule(?User $admin = null, array $overrides = []): ComplianceRule
{
    $uuid = asModerator($admin)->postJson('/api/v1/admin/compliance/rules', complianceRulePayload($overrides))
        ->assertCreated()
        ->json('data.uuid');

    return ComplianceRule::query()->where('uuid', $uuid)->sole();
}

const VERIFICATION_NOTE = 'Vérifié par le cabinet Exemple Conseil le 2026-11-10 sur la page source indiquée.';

describe('accès', function (): void {
    it('cache l\'administration des règles derrière un 404 pour les autres rôles', function (string $role): void {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->getJson('/api/v1/admin/compliance/rules')->assertNotFound();
        $this->actingAs($user)->postJson('/api/v1/admin/compliance/rules', complianceRulePayload())->assertNotFound();
        $this->actingAs($user)->getJson('/api/v1/admin/compliance/coverage')->assertNotFound();

        expect(ComplianceRule::query()->count())->toBe(0);
    })->with(['particulier', 'prestataire', 'entrepreneur', 'redacteur']);

    it('exige une session ouverte avec la double authentification', function (): void {
        $this->actingAs(moderator())->postJson('/api/v1/admin/compliance/rules', complianceRulePayload())->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->getJson('/api/v1/admin/compliance/rules')->assertForbidden();
    });
});

describe('création et modification', function (): void {
    it('crée une règle en brouillon, avec un contenu reconstruit et une trace d\'audit', function (): void {
        $response = asModerator()->postJson('/api/v1/admin/compliance/rules', complianceRulePayload())->assertCreated();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.payload.amount', 1_000_000)
            ->assertJsonPath('data.conditions.vat_regimes', [])
            ->assertJsonPath('data.conditions.options', ['income_tax_withholding' => true])
            ->assertJsonPath('data.missing_fields', [])
            ->assertJsonPath('data.history.0.action', 'compliance.rule.created')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.created_by');

        expect(securityLog('compliance.rule_created'))->toHaveCount(1);
    });

    it('accepte un brouillon incomplet (« à compléter ») et liste les champs vides', function (): void {
        asModerator()->postJson('/api/v1/admin/compliance/rules', complianceRulePayload([
            'source_url' => null,
            'payload' => ['amount' => null, 'activity_types' => [], 'prorate_first_year' => false],
        ]))->assertCreated()->assertJsonPath('data.missing_fields', ['source_url', 'payload.amount', 'payload.activity_types']);
    });

    it('refuse les champs fixés par la plateforme', function (string $field, mixed $value): void {
        asModerator()->postJson('/api/v1/admin/compliance/rules', complianceRulePayload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'statut' => ['status', 'verified'],
        'version' => ['version', 9],
        'démonstration' => ['is_demo', true],
        'date de vérification' => ['last_verified_at', '2026-01-01T00:00:00Z'],
        'note de vérification' => ['verification_note', 'Vérifiée par moi-même, promis, sans aucune source.'],
        'auteur' => ['created_by', 1],
        'uuid' => ['uuid', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'],
    ]);

    it('refuse un contenu invalide', function (array $overrides, string $error): void {
        asModerator()->postJson('/api/v1/admin/compliance/rules', complianceRulePayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);
    })->with([
        'pays non pris en charge' => [['country' => 'DE', 'code' => 'de.test.x'], 'country'],
        'code sans le pays' => [['code' => 'test.seuil'], 'code'],
        'code d\'un autre pays' => [['code' => 'be.test.seuil'], 'code'],
        'type inconnu' => [['kind' => 'formula'], 'kind'],
        'clé inconnue dans le contenu' => [['payload' => ['amount' => 1, 'formula' => 'ca * 0.2']], 'payload'],
        'montant décimal' => [['payload' => ['amount' => 1000.5, 'activity_types' => ['sales']]], 'payload.amount'],
        'montant négatif' => [['payload' => ['amount' => -1, 'activity_types' => ['sales']]], 'payload.amount'],
        'montant en texte' => [['payload' => ['amount' => '10 000', 'activity_types' => ['sales']]], 'payload.amount'],
        'activité inconnue' => [['payload' => ['amount' => 1, 'activity_types' => ['crypto']]], 'payload.activity_types.0'],
        'HTML dans le titre' => [['title' => ['fr' => '<script>alert(1)</script>', 'en' => null]], 'title.fr'],
        'titre sans français' => [['title' => ['en' => 'Only English']], 'title.fr'],
        'option inconnue' => [['conditions' => ['options' => ['crypto' => true]]], 'conditions.options'],
        'valeur d\'option invalide' => [['conditions' => ['options' => ['declaration_frequency' => 'yearly']]], 'conditions.options.declaration_frequency'],
        'source en http' => [['source_url' => 'http://www.impots.gouv.fr/page'], 'source_url'],
        'fin avant le début' => [['valid_until' => '2026-12-31'], 'valid_until'],
    ]);

    it('valide la récurrence et les tâches d\'une échéance', function (): void {
        asModerator()->postJson('/api/v1/admin/compliance/rules', complianceRulePayload([
            'kind' => 'deadline',
            'code' => 'fr.test.declaration',
            'payload' => [
                'schedule' => ['type' => 'quarterly', 'months' => [10, 1, 4, 7], 'day' => 31, 'dates' => ['2027-01-01']],
                'tasks' => [['key' => 'preparer', 'title' => ['fr' => 'Préparer la déclaration'], 'service_category' => null]],
            ],
        ]))->assertCreated()
            ->assertJsonPath('data.payload.schedule', ['type' => 'quarterly', 'month' => null, 'day' => 31, 'days' => null, 'offset_months' => null, 'months' => [1, 4, 7, 10], 'dates' => null])
            ->assertJsonPath('data.payload.tasks.0.title', ['fr' => 'Préparer la déclaration', 'en' => null]);

        asModerator()->postJson('/api/v1/admin/compliance/rules', complianceRulePayload([
            'kind' => 'deadline',
            'code' => 'fr.test.declaration-2',
            'payload' => ['schedule' => ['type' => 'every_full_moon']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('payload.schedule.type');
    });

    it('modifie un brouillon avec la version lue, et refuse une version périmée (409)', function (): void {
        $admin = moderator();
        $rule = createdRule($admin);

        asModerator($admin)->patchJson("/api/v1/admin/compliance/rules/{$rule->uuid}", ['version' => 1, 'payload' => ['amount' => 2_000_000, 'activity_types' => ['sales']]])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.payload.amount', 2_000_000)
            ->assertJsonPath('data.payload.prorate_first_year', false);

        asModerator($admin)->patchJson("/api/v1/admin/compliance/rules/{$rule->uuid}", ['version' => 1, 'internal_notes' => 'trop tard'])
            ->assertConflict();
    });

    it('ne change jamais le pays, le type ni le code d\'une règle', function (string $field, string $value): void {
        $admin = moderator();
        $rule = createdRule($admin);

        asModerator($admin)->patchJson("/api/v1/admin/compliance/rules/{$rule->uuid}", ['version' => 1, $field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([['country', 'BE'], ['kind', 'rate'], ['code', 'fr.test.autre']]);

    it('refuse une date de fin antérieure à la date de début actuelle', function (): void {
        $admin = moderator();
        $rule = createdRule($admin);

        asModerator($admin)->patchJson("/api/v1/admin/compliance/rules/{$rule->uuid}", ['version' => 1, 'valid_until' => '2026-06-30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_until');
    });

    it('répond 404 pour une règle inconnue', function (): void {
        asModerator()->patchJson('/api/v1/admin/compliance/rules/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', ['version' => 1])->assertNotFound();
        asModerator()->getJson('/api/v1/admin/compliance/rules/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')->assertNotFound();
    });
});

describe('vérification', function (): void {
    it('exige une note de vérification d\'au moins 30 caractères', function (?string $note): void {
        $admin = moderator();
        $rule = createdRule($admin);

        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", array_filter(['version' => 1, 'verification_note' => $note]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification_note');

        expect($rule->fresh()->status)->toBe(RuleStatus::Draft);
    })->with([
        'absente' => [null],
        'trop courte' => ['Vérifié par moi.'],
        'avec du HTML' => ['<b>Vérifié</b> par le cabinet Exemple Conseil le 2026-11-10 sur la page.'],
    ]);

    it('vérifie une règle complète, garde la note et la trace dans le journal d\'audit, jamais dans le canal security', function (): void {
        $admin = moderator();
        $rule = createdRule($admin);

        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", ['version' => 1, 'verification_note' => VERIFICATION_NOTE])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.verification.note', VERIFICATION_NOTE)
            ->assertJsonPath('data.verification.verified_at', '2026-11-13T10:00:00Z');

        $audit = AdminAuditLog::query()->where('action', 'compliance.rule.verified')->sole();
        expect($audit->reason)->toBe(VERIFICATION_NOTE)
            ->and($audit->subject_uuid)->toBe($rule->uuid)
            ->and(securityLog('compliance.rule_verified'))->toHaveCount(1)
            ->and(json_encode(array_map(fn ($record) => $record->context, securityLog())))->not->toContain('Exemple Conseil');
    });

    it('refuse de vérifier une règle incomplète (422, avec les champs à compléter)', function (): void {
        $admin = moderator();
        $rule = createdRule($admin, ['payload' => ['amount' => null, 'activity_types' => ['sales']]]);

        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", ['version' => 1, 'verification_note' => VERIFICATION_NOTE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rule');

        expect($rule->fresh()->status)->toBe(RuleStatus::Draft);
    });

    it('refuse une version périmée (409) et une règle déjà vérifiée (409)', function (): void {
        $admin = moderator();
        $rule = createdRule($admin);

        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", ['version' => 3, 'verification_note' => VERIFICATION_NOTE])->assertConflict();
        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", ['version' => 1, 'verification_note' => VERIFICATION_NOTE])->assertOk();
        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", ['version' => 1, 'verification_note' => VERIFICATION_NOTE])->assertConflict();
        asModerator($admin)->patchJson("/api/v1/admin/compliance/rules/{$rule->uuid}", ['version' => 1, 'internal_notes' => 'modif'])->assertConflict();
    });

    it('laisse l\'auteur vérifier sa règle tant que les quatre yeux sont désactivés (réglage par défaut)', function (): void {
        expect(config('compliance.require_distinct_verifier'))->toBeFalse();

        $admin = moderator();
        $rule = createdRule($admin);

        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", ['version' => 1, 'verification_note' => VERIFICATION_NOTE])->assertOk();
    });

    it('exige un autre admin que le dernier auteur quand les quatre yeux sont activés', function (): void {
        config(['compliance.require_distinct_verifier' => true]);
        $author = moderator();
        $rule = createdRule($author);

        asModerator($author)->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", ['version' => 1, 'verification_note' => VERIFICATION_NOTE])
            ->assertForbidden();
        expect(securityLog('compliance.rule_self_verification_denied'))->toHaveCount(1)
            ->and($rule->fresh()->status)->toBe(RuleStatus::Draft);

        asModerator(moderator())->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", ['version' => 1, 'verification_note' => VERIFICATION_NOTE])
            ->assertOk();
    });

    it('refuse deux versions vérifiées d\'un même code sur des périodes qui se chevauchent (409)', function (): void {
        $admin = moderator();
        ComplianceRule::factory()->verified()->threshold('fr.test.seuil-services', 900_000)->create(['valid_from' => '2026-01-01', 'valid_until' => null]);
        $rule = createdRule($admin);

        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$rule->uuid}/verify", ['version' => 1, 'verification_note' => VERIFICATION_NOTE])
            ->assertConflict();
    });
});

describe('nouvelle version, retrait et suppression', function (): void {
    it('crée une nouvelle version en brouillon : l\'ancienne reste en vigueur jusqu\'à la vérification', function (): void {
        $admin = moderator();
        $current = ComplianceRule::factory()->verified()->threshold('fr.test.seuil', 900_000)->create();

        $draftUuid = asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$current->uuid}/revise")
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.replaces', $current->uuid)
            ->assertJsonPath('data.payload.amount', 900_000)
            ->json('data.uuid');

        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$current->uuid}/revise")->assertConflict();

        asModerator($admin)->patchJson("/api/v1/admin/compliance/rules/{$draftUuid}", ['version' => 1, 'payload' => ['amount' => 950_000, 'activity_types' => ['services_commercial']]])->assertOk();
        expect($current->fresh()->status)->toBe(RuleStatus::Verified);

        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$draftUuid}/verify", ['version' => 2, 'verification_note' => VERIFICATION_NOTE])->assertOk();

        expect($current->fresh()->status)->toBe(RuleStatus::Retired)
            ->and(ComplianceRule::query()->where('uuid', $draftUuid)->sole()->status)->toBe(RuleStatus::Verified)
            ->and(AdminAuditLog::query()->where('action', 'compliance.rule.retired')->where('subject_uuid', $current->uuid)->exists())->toBeTrue();
    });

    it('retire une règle vérifiée avec un motif, et refuse de retirer un brouillon', function (): void {
        $admin = moderator();
        $verified = ComplianceRule::factory()->verified()->create();
        $draft = ComplianceRule::factory()->create();

        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$verified->uuid}/retire", ['version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$verified->uuid}/retire", ['version' => 1, 'reason' => 'Règle abrogée par la loi de finances.'])
            ->assertOk()->assertJsonPath('data.status', 'retired');
        asModerator($admin)->postJson("/api/v1/admin/compliance/rules/{$draft->uuid}/retire", ['version' => 1, 'reason' => 'Règle abrogée par la loi de finances.'])
            ->assertConflict();
    });

    it('supprime un brouillon, jamais une règle vérifiée', function (): void {
        $admin = moderator();
        $verified = ComplianceRule::factory()->verified()->create();
        $draft = ComplianceRule::factory()->create();

        asModerator($admin)->deleteJson("/api/v1/admin/compliance/rules/{$draft->uuid}")->assertNoContent();
        asModerator($admin)->deleteJson("/api/v1/admin/compliance/rules/{$verified->uuid}")->assertConflict();

        expect(ComplianceRule::query()->count())->toBe(1);
    });
});

describe('liste', function (): void {
    it('filtre les règles par pays, statut, type et année', function (): void {
        ComplianceRule::factory()->verified()->threshold('fr.test.a', 1)->create(['valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);
        ComplianceRule::factory()->threshold('fr.test.b', null)->create(['valid_from' => '2027-01-01']);
        ComplianceRule::factory()->verified()->create();

        asModerator()->getJson('/api/v1/admin/compliance/rules?kind=threshold&year=2027')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'fr.test.b');

        asModerator()->getJson('/api/v1/admin/compliance/rules?status=verified')->assertOk()->assertJsonCount(2, 'data');
        asModerator()->getJson('/api/v1/admin/compliance/rules?status=published')->assertUnprocessable();
        asModerator()->getJson('/api/v1/admin/compliance/rules?per_page=500')->assertUnprocessable();
    });
});

it('limite les écritures de règles par admin (429)', function (): void {
    config(['security.rate_limits.compliance_rules_admin.per_hour' => 2]);
    $admin = moderator();

    asModerator($admin)->postJson('/api/v1/admin/compliance/rules', complianceRulePayload(['code' => 'fr.test.a']))->assertCreated();
    asModerator($admin)->postJson('/api/v1/admin/compliance/rules', complianceRulePayload(['code' => 'fr.test.b']))->assertCreated();
    asModerator($admin)->postJson('/api/v1/admin/compliance/rules', complianceRulePayload(['code' => 'fr.test.c']))->assertTooManyRequests();
});
