<?php

use App\Http\Middleware\EnsureModerator;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Support\AdminAudit;
use App\Support\TwoFactorSession;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;

/*
| Accès à la modération (noyau) : rôle admin ET double authentification, au niveau du compte
| et de la session. Journal d'audit des décisions d'administration.
*/

/**
 * Route de test protégée comme les endpoints de modération.
 */
function registerModerationProbe(): void
{
    Route::middleware(['api', 'auth:sanctum', 'verified', EnsureModerator::class])
        ->get('api/v1/admin/probe', fn () => response()->json(['ok' => true]));
}

describe('EnsureModerator', function (): void {
    beforeEach(fn () => registerModerationProbe());

    it('refuse un visiteur anonyme (401)', function (): void {
        $this->getJson('/api/v1/admin/probe')->assertUnauthorized();
    });

    it('cache la modération derrière un 404 pour tous les rôles sauf admin, même avec la 2FA', function (string $role): void {
        $user = userWithTwoFactor();
        $user->assignRole($role);

        asModerator($user)->getJson('/api/v1/admin/probe')->assertNotFound();

        expect(securityLog('moderation.access_denied')[0]->context)->toMatchArray(['reason' => 'role', 'user_id' => $user->id]);
    })->with(['particulier', 'prestataire', 'entrepreneur', 'redacteur']);

    it('refuse un admin sans double authentification (403, avec un message)', function (): void {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        asModerator($admin)
            ->getJson('/api/v1/admin/probe')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'double authentification'));

        expect(securityLog('moderation.access_denied')[0]->context['reason'])->toBe('two_factor_disabled');
    });

    it('refuse un admin dont la 2FA est activée mais pas confirmée', function (): void {
        $admin = userWithTwoFactor();
        $admin->forceFill(['two_factor_confirmed_at' => null])->save();
        $admin->assignRole('admin');

        asModerator($admin)->getJson('/api/v1/admin/probe')->assertForbidden();
    });

    it('refuse un admin avec 2FA dont la session n\'a pas présenté de code (403)', function (): void {
        $admin = userWithTwoFactor();
        $admin->assignRole('admin');

        fromFrontend()->actingAs($admin)
            ->getJson('/api/v1/admin/probe')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Reconnectez-vous'));

        expect(securityLog('moderation.access_denied')[0]->context['reason'])->toBe('two_factor_session');
    });

    it('accepte un admin avec 2FA dont la session a présenté un code', function (): void {
        $admin = userWithTwoFactor();
        $admin->assignRole('admin');

        asModerator($admin)->getJson('/api/v1/admin/probe')->assertOk();
    });

    it('refuse un admin dont l\'email n\'est pas vérifié', function (): void {
        $admin = userWithTwoFactor(['email_verified_at' => null]);
        $admin->assignRole('admin');

        asModerator($admin)->getJson('/api/v1/admin/probe')->assertForbidden();
    });
});

describe('EnsureModerator avec des rôles en paramètre', function (): void {
    beforeEach(function (): void {
        Route::middleware(['api', 'auth:sanctum', 'verified', EnsureModerator::class.':admin,redacteur'])
            ->get('api/v1/admin/editorial-probe', fn () => response()->json(['ok' => true]));
    });

    it('accepte les rôles indiqués, avec la 2FA de session', function (string $role): void {
        $user = userWithTwoFactor();
        $user->assignRole($role);

        asModerator($user)->getJson('/api/v1/admin/editorial-probe')->assertOk();
    })->with(['admin', 'redacteur']);

    it('cache la route derrière un 404 pour les autres rôles', function (string $role): void {
        $user = userWithTwoFactor();
        $user->assignRole($role);

        asModerator($user)->getJson('/api/v1/admin/editorial-probe')->assertNotFound();
    })->with(['particulier', 'prestataire', 'entrepreneur']);

    it('exige aussi la 2FA de session pour un rédacteur', function (): void {
        $user = userWithTwoFactor();
        $user->assignRole('redacteur');

        fromFrontend()->actingAs($user)->getJson('/api/v1/admin/editorial-probe')->assertForbidden();
    });
});

describe('marque 2FA de la session', function (): void {
    it('marque la session ouverte par le défi 2FA (code TOTP)', function (): void {
        registerModerationProbe();
        $admin = userWithTwoFactor();
        $admin->assignRole('admin');
        $code = app(Google2FA::class)->getCurrentOtp(TWO_FACTOR_SECRET);

        $this->withSession(['login.id' => $admin->id])
            ->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])
            ->assertSuccessful()
            ->assertSessionHas(TwoFactorSession::KEY);

        fromFrontend()->getJson('/api/v1/admin/probe')->assertOk();
    });

    it('marque la session ouverte avec un code de secours', function (): void {
        $admin = userWithTwoFactor();

        $this->withSession(['login.id' => $admin->id])
            ->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'code-de-secours-1'])
            ->assertSuccessful()
            ->assertSessionHas(TwoFactorSession::KEY);
    });

    it('ne marque pas une connexion sans 2FA', function (): void {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSuccessful()
            ->assertSessionMissing(TwoFactorSession::KEY);
    });

    it('retire la marque quand la 2FA est désactivée', function (): void {
        $user = userWithTwoFactor();

        $this->actingAs($user)
            ->withSession([TwoFactorSession::KEY => time(), 'auth.password_confirmed_at' => time()])
            ->deleteJson('/api/v1/auth/user/two-factor-authentication')
            ->assertOk()
            ->assertSessionMissing(TwoFactorSession::KEY);
    });
});

describe('journal d\'audit des décisions d\'administration', function (): void {
    it('enregistre l\'admin, l\'action, l\'élément, la version, le motif, l\'IP et la route', function (): void {
        $admin = User::factory()->create();

        $entry = AdminAudit::record($admin, 'events.event.rejected', 'event', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 3, 'Lien vers un site inconnu.');

        expect($entry->fresh())
            ->admin_id->toBe($admin->id)
            ->action->toBe('events.event.rejected')
            ->subject_type->toBe('event')
            ->version->toBe(3)
            ->reason->toBe('Lien vers un site inconnu.')
            ->and($entry->fresh()->metadata)->toHaveKeys(['ip', 'route'])
            ->and(AdminAudit::historyOf('event', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'))->toHaveCount(1);
    });

    it('ne se modifie pas et ne se supprime pas ligne par ligne', function (): void {
        $entry = AdminAudit::record(User::factory()->create(), 'events.event.approved', 'event', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d');

        expect(fn () => $entry->forceFill(['action' => 'events.event.rejected'])->save())->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class)
            ->and(AdminAuditLog::query()->sole()->action)->toBe('events.event.approved');
    });

    it('survit à la suppression du compte de l\'admin', function (): void {
        $admin = User::factory()->create();
        AdminAudit::record($admin, 'events.event.approved', 'event', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d');

        $admin->delete();

        expect(AdminAuditLog::query()->sole()->admin_id)->toBe($admin->id);
    });

    it('purge les entrées au-delà de la durée de conservation', function (): void {
        $admin = User::factory()->create();
        $this->travelTo(now()->subMonths(25));
        AdminAudit::record($admin, 'events.event.approved', 'event', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d');
        $this->travelBack();
        AdminAudit::record($admin, 'events.event.rejected', 'event', '1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed');

        $this->artisan('admin-audit:prune')->assertSuccessful();

        expect(AdminAuditLog::query()->pluck('action')->all())->toBe(['events.event.rejected']);
    });
});
