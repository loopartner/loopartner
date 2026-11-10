<?php

use App\Models\User;
use App\Modules\Directory\Models\Project;
use App\Notifications\ResetPasswordNotification;
use App\Support\SecurityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Monolog\LogRecord;

/*
| Journal des événements de sécurité (ASVS chapitre 7), canal "security". Chaque entrée contient le
| type d'événement, l'utilisateur s'il est connu, l'IP, la date et la route ; jamais de mot de
| passe, de jeton, de code 2FA ni d'email en clair.
*/

const LOG_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function (): void {
    Notification::fake();
    Carbon::setTestNow('2026-11-10 09:00:00');
});

/**
 * Unique entrée du journal pour cet événement.
 */
function securityEvent(string $event): LogRecord
{
    $records = securityLog($event);

    expect($records)->toHaveCount(1, "Événement {$event} attendu une fois, trouvé ".count($records).' fois.');

    return $records[0];
}

/**
 * Aucune entrée du journal ne contient ces valeurs.
 *
 * @param  list<string>  $secrets
 */
function assertLogContainsNone(array $secrets): void
{
    $logged = json_encode(array_map(fn (LogRecord $record): array => $record->toArray(), securityLog()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach ($secrets as $secret) {
        expect($logged)->not->toContain($secret);
    }
}

it('donne à chaque entrée le type, l\'utilisateur, l\'IP, la date et la route', function (): void {
    User::factory()->create(['email' => 'camille@example.com', 'password' => LOG_PASSWORD]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/auth/login', ['email' => 'camille@example.com', 'password' => LOG_PASSWORD])
        ->assertOk();

    expect(securityEvent('auth.login')->context)->toMatchArray([
        'event' => 'auth.login',
        'ip' => '203.0.113.9',
        'route' => 'POST /api/v1/auth/login',
        'at' => '2026-11-10T09:00:00Z',
    ])->and(securityEvent('auth.login')->context['user_id'])->toBe(User::query()->sole()->id);

    assertLogContainsNone(['camille@example.com', LOG_PASSWORD]);
});

describe('connexion', function (): void {
    it('journalise une connexion échouée sur un compte existant, avec l\'empreinte de l\'email', function (): void {
        $user = User::factory()->create(['email' => 'camille@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'camille@example.com', 'password' => 'mauvais-mot-de-passe'])
            ->assertUnprocessable();

        $record = securityEvent('auth.login_failed');
        expect($record->level->getName())->toBe('WARNING')
            ->and($record->context)->toMatchArray([
                'user_id' => $user->id,
                'email_hash' => SecurityLog::emailHash('camille@example.com'),
            ]);

        assertLogContainsNone(['camille@example.com', 'mauvais-mot-de-passe']);
    });

    it('relie les tentatives sur un compte inexistant par l\'empreinte de l\'email', function (): void {
        $this->postJson('/api/v1/auth/login', ['email' => 'Inconnue@example.com', 'password' => 'mauvais-mot-de-passe'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'inconnue@example.com', 'password' => 'autre-essai-incorrect'])->assertUnprocessable();

        $records = securityLog('auth.login_failed');
        expect($records)->toHaveCount(2)
            ->and($records[0]->context['user_id'])->toBeNull()
            ->and($records[0]->context['email_hash'])->toBe($records[1]->context['email_hash'])
            ->and($records[0]->context['email_hash'])->toBe(SecurityLog::emailHash('inconnue@example.com'));

        assertLogContainsNone(['inconnue@example.com', 'Inconnue@example.com', 'mauvais-mot-de-passe', 'autre-essai-incorrect']);
    });

    it('journalise la déconnexion', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();

        expect(securityEvent('auth.logout')->context['user_id'])->toBe($user->id);
    });

    it('journalise le blocage par une limite de débit', function (): void {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['email' => 'camille@example.com', 'password' => 'mauvais-mot-de-passe']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/v1/auth/login', ['email' => 'camille@example.com', 'password' => 'mauvais-mot-de-passe'])
            ->assertTooManyRequests();

        $record = securityEvent('rate_limit.exceeded');
        expect($record->level->getName())->toBe('WARNING')
            ->and($record->context)->toMatchArray(['route' => 'POST /api/v1/auth/login', 'user_id' => null])
            ->and($record->context['retry_after'])->toBeGreaterThan(0);

        assertLogContainsNone(['camille@example.com', 'mauvais-mot-de-passe']);
    });
});

describe('double authentification', function (): void {
    it('journalise un défi 2FA échoué, sans le code', function (): void {
        $user = userWithTwoFactor();

        $this->withSession(['login.id' => $user->id])
            ->postJson('/api/v1/auth/two-factor-challenge', ['code' => '987123'])
            ->assertUnprocessable();
        $this->withSession(['login.id' => $user->id])
            ->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => 'code-de-secours-invente'])
            ->assertUnprocessable();

        $records = securityLog('auth.two_factor_failed');
        expect($records)->toHaveCount(2)
            ->and($records[0]->level->getName())->toBe('WARNING')
            ->and($records[0]->context['user_id'])->toBe($user->id);

        assertLogContainsNone(['987123', 'code-de-secours-invente', TWO_FACTOR_SECRET, 'code-de-secours-1']);
    });

    it('journalise l\'activation et la désactivation de la 2FA, sans secret ni codes de secours', function (): void {
        $user = User::factory()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($confirmed)->postJson('/api/v1/auth/user/two-factor-authentication')->assertOk();
        $this->actingAs($user)->withSession($confirmed)->postJson('/api/v1/auth/user/two-factor-recovery-codes')->assertOk();
        $this->actingAs($user)->withSession($confirmed)->deleteJson('/api/v1/auth/user/two-factor-authentication')->assertOk();

        expect(securityEvent('auth.two_factor_enabled')->context['user_id'])->toBe($user->id)
            ->and(securityEvent('auth.two_factor_recovery_codes_regenerated')->context['user_id'])->toBe($user->id)
            ->and(securityEvent('auth.two_factor_disabled')->context['user_id'])->toBe($user->id);

        $user->refresh();
        assertLogContainsNone(array_filter([(string) $user->two_factor_secret]));
    });
});

describe('mot de passe', function (): void {
    it('journalise chaque demande de réinitialisation avec son issue, sans email ni jeton', function (): void {
        $user = User::factory()->create(['email' => 'camille@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'camille@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'camille@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnue@example.com'])->assertOk();

        $records = securityLog('auth.password_reset_requested');
        expect(array_map(fn (LogRecord $record) => $record->context['outcome'], $records))->toBe(['sent', 'throttled', 'unknown_email'])
            ->and($records[0]->context['email_hash'])->toBe(SecurityLog::emailHash('camille@example.com'));

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        assertLogContainsNone(['camille@example.com', 'inconnue@example.com', (string) $token]);
    });

    it('journalise la réinitialisation effectuée, sans jeton ni mot de passe', function (): void {
        $user = User::factory()->create(['email' => 'camille@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'camille@example.com',
            'password' => LOG_PASSWORD,
            'password_confirmation' => LOG_PASSWORD,
        ])->assertOk();

        expect(securityEvent('auth.password_reset')->context['user_id'])->toBe($user->id);
        assertLogContainsNone([$token, LOG_PASSWORD, 'camille@example.com']);
    });

    it('journalise le changement de mot de passe', function (): void {
        $user = User::factory()->create(['password' => 'ancien-mot-de-passe-solide']);

        $this->actingAs($user)->putJson('/api/v1/auth/user/password', [
            'current_password' => 'ancien-mot-de-passe-solide',
            'password' => LOG_PASSWORD,
            'password_confirmation' => LOG_PASSWORD,
        ])->assertOk();

        expect(securityEvent('auth.password_changed')->context['user_id'])->toBe($user->id);
        assertLogContainsNone(['ancien-mot-de-passe-solide', LOG_PASSWORD]);
    });
});

describe('compte', function (): void {
    it('journalise l\'inscription, y compris sur une adresse déjà utilisée, sans email', function (): void {
        $existing = User::factory()->create(['email' => 'deja@example.com']);

        $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);
        $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'deja@example.com']))->assertStatus(202);

        $created = User::query()->where('email', 'camille@example.com')->sole();
        expect(securityEvent('auth.registered')->context['user_id'])->toBe($created->id)
            ->and(securityEvent('auth.registration_existing_email')->context)->toMatchArray([
                'user_id' => $existing->id,
                'email_hash' => SecurityLog::emailHash('deja@example.com'),
            ]);

        assertLogContainsNone(['camille@example.com', 'deja@example.com', 'correct-horse-battery-staple', 'Camille Martin']);
    });

    it('journalise la vérification de l\'email et les liens refusés', function (): void {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $url = verificationUrlFor($user);

        $this->get(preg_replace('/signature=[^&]+/', 'signature='.str_repeat('a', 64), $url))->assertRedirect();
        $this->get($url)->assertRedirect();

        expect(securityEvent('auth.email_verification_failed')->context)->toMatchArray(['reason' => 'invalid'])
            ->and(securityEvent('auth.email_verified')->context['user_id'])->toBe($user->id);
    });

    it('journalise la suppression du compte, sans mot de passe', function (): void {
        $user = User::factory()->create(['password' => LOG_PASSWORD]);
        $id = $user->id;

        $this->actingAs($user)->deleteJson('/api/v1/me', ['password' => LOG_PASSWORD])->assertNoContent();

        expect(securityEvent('account.deleted')->context['user_id'])->toBe($id);
        assertLogContainsNone([LOG_PASSWORD]);
    });
});

describe('refus d\'autorisation', function (): void {
    it('journalise l\'accès au projet d\'un autre utilisateur (404)', function (): void {
        $project = Project::factory()->published()->create();
        $intruder = projectOwner('particulier');

        $this->actingAs($intruder)->patchJson("/api/v1/me/projects/{$project->uuid}", ['title' => 'Piraté !'])->assertNotFound();

        $record = securityEvent('authorization.denied');
        expect($record->level->getName())->toBe('WARNING')
            ->and($record->context)->toMatchArray([
                'user_id' => $intruder->id,
                'ability' => 'update',
                'resource' => 'Project',
                'resource_ref' => $project->uuid,
                'status' => 404,
                'route' => "PATCH /api/v1/me/projects/{$project->uuid}",
            ]);
    });

    it('journalise l\'accès à une fonction réservée à un autre rôle (403)', function (): void {
        $user = projectOwner('particulier');

        $this->actingAs($user)->getJson('/api/v1/projects')->assertForbidden();

        expect(securityEvent('authorization.denied')->context)->toMatchArray([
            'user_id' => $user->id,
            'ability' => 'browse',
            'resource' => 'Project',
            'resource_ref' => null,
            'status' => 403,
        ]);
    });

    it('ne journalise pas un accès autorisé', function (): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->getJson("/api/v1/me/projects/{$project->uuid}")->assertOk();

        expect(securityLog('authorization.denied'))->toBe([]);
    });
});
