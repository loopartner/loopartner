<?php

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Modules\Accounts\Actions\GetPremiumStatus;
use App\Modules\Accounts\Actions\IsPremium;
use App\Modules\Accounts\Enums\PremiumSource;
use App\Modules\Accounts\Models\PremiumGrant;

/*
| Statut premium (module Accounts) : attribution manuelle par un admin, Actions publiques,
| statut visible dans GET /me.
*/

function premiumCandidate(string $role = 'entrepreneur', string $email = 'client@example.com'): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->assignRole($role);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function grantPayload(array $overrides = []): array
{
    return [
        'email' => 'client@example.com',
        'reason' => 'Partenaire du lancement, offert six mois.',
        ...$overrides,
    ];
}

describe('Actions publiques', function (): void {
    it('indique si un utilisateur est premium selon ses périodes', function (): void {
        $active = PremiumGrant::factory()->create();
        $ended = PremiumGrant::factory()->ended()->create();
        $revoked = PremiumGrant::factory()->create(['revoked_at' => now()]);
        $upcoming = PremiumGrant::factory()->create(['starts_at' => now()->addDay()]);
        $limited = PremiumGrant::factory()->create(['ends_at' => now()->addMonth()]);

        $isPremium = app(IsPremium::class);

        expect($isPremium->handle($active->user_id))->toBeTrue()
            ->and($isPremium->handle($ended->user_id))->toBeFalse()
            ->and($isPremium->handle($revoked->user_id))->toBeFalse()
            ->and($isPremium->handle($upcoming->user_id))->toBeFalse()
            ->and($isPremium->handle($limited->user_id))->toBeTrue()
            ->and($isPremium->handle(User::factory()->create()->id))->toBeFalse();
    });

    it('cesse d\'être premium à la fin de la période, sans tâche planifiée', function (): void {
        $grant = PremiumGrant::factory()->create(['ends_at' => now()->addHour()]);

        expect(app(IsPremium::class)->handle($grant->user_id))->toBeTrue();

        $this->travel(2)->hours();

        expect(app(IsPremium::class)->handle($grant->user_id))->toBeFalse();
    });

    it('donne le statut en données simples, sans motif ni admin', function (): void {
        $grant = PremiumGrant::factory()->create(['ends_at' => now()->addMonth()->startOfSecond(), 'granted_by' => 99]);

        expect(app(GetPremiumStatus::class)->handle($grant->user_id))->toBe([
            'active' => true,
            'source' => 'manual',
            'starts_at' => $grant->starts_at->toIso8601ZuluString(),
            'ends_at' => $grant->ends_at?->toIso8601ZuluString(),
        ]);
    });
});

describe('GET /me', function (): void {
    it('montre son statut premium', function (): void {
        $grant = PremiumGrant::factory()->create();

        $this->actingAs($grant->user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.premium.active', true)
            ->assertJsonPath('data.premium.source', 'manual')
            ->assertJsonPath('data.premium.ends_at', null);
    });

    it('ne permet pas de se donner le statut premium', function (): void {
        $user = premiumCandidate();

        $this->actingAs($user)
            ->patchJson('/api/v1/me', ['premium' => true, 'premium_until' => '2099-01-01'])
            ->assertOk()
            ->assertJsonPath('data.premium.active', false);

        expect(PremiumGrant::query()->count())->toBe(0);
    });
});

describe('attribution par un admin', function (): void {
    it('accorde le statut premium (201), avec journal d\'audit et journal security', function (): void {
        $user = premiumCandidate();
        $admin = moderator();

        asModerator($admin)
            ->postJson('/api/v1/admin/premium-grants', grantPayload(['ends_at' => now()->addMonths(6)->toIso8601ZuluString()]))
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.active', true)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.granted_by');

        $grant = PremiumGrant::query()->sole();
        $audit = AdminAuditLog::query()->sole();
        $log = securityLog('premium.granted')[0]->context;

        expect($grant->user_id)->toBe($user->id)
            ->and($grant->granted_by)->toBe($admin->id)
            ->and($grant->source)->toBe(PremiumSource::Manual)
            ->and(app(IsPremium::class)->handle($user->id))->toBeTrue()
            ->and($audit->action)->toBe('accounts.premium.granted')
            ->and($audit->subject_uuid)->toBe($grant->uuid)
            ->and($audit->reason)->toBe('Partenaire du lancement, offert six mois.')
            ->and($log)->toMatchArray(['user_id' => $admin->id, 'subject_user_id' => $user->id, 'grant' => $grant->uuid])
            ->and(json_encode($log))->not->toContain('client@example.com')
            ->and(json_encode($log))->not->toContain('Partenaire');
    });

    it('accepte une période à venir et une période sans fin', function (): void {
        premiumCandidate('prestataire');

        asModerator()
            ->postJson('/api/v1/admin/premium-grants', grantPayload(['starts_at' => now()->addWeek()->toIso8601ZuluString()]))
            ->assertCreated()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.ends_at', null);
    });

    it('refuse une seconde période en cours ou à venir (409)', function (): void {
        $user = premiumCandidate();
        PremiumGrant::factory()->for($user)->create(['starts_at' => now()->addMonth()]);

        asModerator()->postJson('/api/v1/admin/premium-grants', grantPayload())->assertConflict();
    });

    it('accorde de nouveau après une période terminée', function (): void {
        $user = premiumCandidate();
        PremiumGrant::factory()->for($user)->ended()->create();

        asModerator()->postJson('/api/v1/admin/premium-grants', grantPayload())->assertCreated();
    });

    it('refuse l\'équipe et un compte inconnu (422)', function (string $case): void {
        if ($case === 'équipe') {
            premiumCandidate('redacteur');
        }

        asModerator()
            ->postJson('/api/v1/admin/premium-grants', grantPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    })->with(['équipe', 'compte inconnu']);

    it('valide les dates et le motif (422)', function (array $payload, string $field): void {
        premiumCandidate();

        asModerator()
            ->postJson('/api/v1/admin/premium-grants', grantPayload($payload))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'fin passée' => [['ends_at' => '2020-01-01T00:00:00Z'], 'ends_at'],
        'fin avant le début' => [['starts_at' => now()->addMonth()->toIso8601ZuluString(), 'ends_at' => now()->addWeek()->toIso8601ZuluString()], 'ends_at'],
        'début passé' => [['starts_at' => '2020-01-01T00:00:00Z'], 'starts_at'],
        'date invalide' => [['ends_at' => 'demain'], 'ends_at'],
        'motif absent' => [['reason' => null], 'reason'],
        'motif trop court' => [['reason' => 'court'], 'reason'],
        'motif en HTML' => [['reason' => '<b>Offert</b> au partenaire'], 'reason'],
    ]);

    it('ignore les champs fixés par la plateforme', function (): void {
        premiumCandidate();

        asModerator()
            ->postJson('/api/v1/admin/premium-grants', grantPayload(['source' => 'payment', 'external_reference' => 'sub_123', 'user_id' => 1]))
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual');

        expect(PremiumGrant::query()->sole()->external_reference)->toBeNull();
    });

    it('liste l\'historique d\'un compte avec son statut, 404 pour un compte inconnu', function (): void {
        $user = premiumCandidate();
        PremiumGrant::factory()->for($user)->ended()->create();
        PremiumGrant::factory()->for($user)->create();

        asModerator()
            ->getJson('/api/v1/admin/premium-grants?email=client@example.com')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.1.active', false)
            ->assertJsonPath('meta.premium.active', true);

        asModerator()->getJson('/api/v1/admin/premium-grants?email=inconnu@example.com')->assertNotFound();
        asModerator()->getJson('/api/v1/admin/premium-grants')->assertUnprocessable();
    });
});

describe('retrait par un admin', function (): void {
    it('retire une période en cours, avec motif dans le journal d\'audit', function (): void {
        $grant = PremiumGrant::factory()->create();

        asModerator()
            ->postJson("/api/v1/admin/premium-grants/{$grant->uuid}/revoke", ['reason' => 'Fin du partenariat de lancement.'])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        expect(app(IsPremium::class)->handle($grant->user_id))->toBeFalse()
            ->and(AdminAuditLog::query()->sole()->action)->toBe('accounts.premium.revoked')
            ->and(securityLog('premium.revoked'))->toHaveCount(1);
    });

    it('refuse de retirer une période terminée, retirée ou payée (409)', function (array $state): void {
        $grant = PremiumGrant::factory()->create($state);

        asModerator()
            ->postJson("/api/v1/admin/premium-grants/{$grant->uuid}/revoke", ['reason' => 'Fin du partenariat de lancement.'])
            ->assertConflict();
    })->with([
        'terminée' => [['starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay()]],
        'retirée' => [['revoked_at' => now()]],
        'payée' => [['source' => PremiumSource::Payment, 'external_reference' => 'sub_123']],
    ]);

    it('répond 404 pour une période inconnue', function (): void {
        asModerator()
            ->postJson('/api/v1/admin/premium-grants/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/revoke', ['reason' => 'Fin du partenariat de lancement.'])
            ->assertNotFound();
    });
});

describe('autorisation et limites', function (): void {
    it('refuse un visiteur anonyme (401)', function (): void {
        $this->postJson('/api/v1/admin/premium-grants', grantPayload())->assertUnauthorized();
    });

    it('cache l\'administration du premium à tout autre rôle, rédacteur compris (404)', function (string $role): void {
        premiumCandidate();
        $user = userWithTwoFactor();
        $user->assignRole($role);

        asModerator($user)->postJson('/api/v1/admin/premium-grants', grantPayload())->assertNotFound();
        asModerator($user)->getJson('/api/v1/admin/premium-grants?email=client@example.com')->assertNotFound();

        expect(PremiumGrant::query()->count())->toBe(0);
    })->with(['particulier', 'prestataire', 'entrepreneur', 'redacteur']);

    it('refuse un admin sans 2FA de session (403)', function (): void {
        premiumCandidate();
        $admin = moderator();

        fromFrontend()->actingAs($admin)->postJson('/api/v1/admin/premium-grants', grantPayload())->assertForbidden();
    });

    it('limite les décisions de chaque admin (429)', function (): void {
        config(['security.rate_limits.premium_admin.per_hour' => 2]);
        $admin = moderator();
        $grants = PremiumGrant::factory()->count(3)->create();

        foreach ($grants->take(2) as $grant) {
            asModerator($admin)->postJson("/api/v1/admin/premium-grants/{$grant->uuid}/revoke", ['reason' => 'Fin du partenariat de lancement.'])->assertOk();
        }

        asModerator($admin)
            ->postJson("/api/v1/admin/premium-grants/{$grants[2]->uuid}/revoke", ['reason' => 'Fin du partenariat de lancement.'])
            ->assertTooManyRequests();
    });

    it('supprime les périodes avec le compte', function (): void {
        $grant = PremiumGrant::factory()->create();

        $grant->user->delete();

        expect(PremiumGrant::query()->count())->toBe(0);
    });
});
