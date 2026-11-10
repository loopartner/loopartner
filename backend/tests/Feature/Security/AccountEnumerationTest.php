<?php

use App\Models\User;
use App\Modules\Accounts\Notifications\RegistrationAttemptNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
| Énumération des comptes : l'inscription et le mot de passe oublié répondent exactement de la
| même façon, que l'adresse email corresponde à un compte ou non.
*/

beforeEach(function (): void {
    Notification::fake();
    config(['app.frontend_url' => 'https://app.loopartner.test']);
});

describe('inscription', function (): void {
    it('répond 202 sans connecter l\'utilisateur et envoie le lien de vérification par la file d\'attente', function (): void {
        $this->postJson('/api/v1/auth/register', registrationPayload())
            ->assertStatus(202)
            ->assertExactJson(['message' => 'Vérifiez votre boîte mail pour terminer votre inscription.']);

        $this->assertGuest();

        $user = User::query()->where('email', 'camille@example.com')->sole();
        expect($user->hasVerifiedEmail())->toBeFalse()
            ->and($user->getRoleNames()->all())->toBe(['particulier']);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        Notification::assertNotSentTo($user, RegistrationAttemptNotification::class);
        expect(new VerifyEmailNotification)->toBeInstanceOf(ShouldQueue::class);
    });

    it('répond de façon identique pour une adresse déjà utilisée, sans rien modifier', function (): void {
        $existing = User::factory()->create([
            'name' => 'Titulaire',
            'email' => 'camille@example.com',
            'password' => Hash::make('mot-de-passe-du-titulaire'),
        ]);
        $existing->assignRole('prestataire');

        $newResponse = $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'nouvelle@example.com']));
        $existingResponse = $this->postJson('/api/v1/auth/register', registrationPayload([
            'email' => 'Camille@Example.com',
            'name' => 'Usurpateur',
            'account_type' => 'entrepreneur',
        ]));

        expect($existingResponse->status())->toBe($newResponse->status())
            ->and($existingResponse->getContent())->toBe($newResponse->getContent());

        $this->assertGuest();
        $existing->refresh();
        expect(User::query()->where('email', 'camille@example.com')->count())->toBe(1)
            ->and($existing->name)->toBe('Titulaire')
            ->and(Hash::check('mot-de-passe-du-titulaire', $existing->password))->toBeTrue()
            ->and($existing->getRoleNames()->all())->toBe(['prestataire']);

        // Le titulaire est prévenu, par la file d'attente ; aucun lien de vérification ne lui est envoyé.
        Notification::assertSentTo($existing, RegistrationAttemptNotification::class);
        Notification::assertNotSentTo($existing, VerifyEmailNotification::class);
        expect(new RegistrationAttemptNotification)->toBeInstanceOf(ShouldQueue::class);
    });

    it('prévient le titulaire avec des liens vers la connexion et la réinitialisation du frontend', function (): void {
        $existing = User::factory()->create(['email' => 'camille@example.com']);

        $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

        Notification::assertSentTo($existing, RegistrationAttemptNotification::class, function (RegistrationAttemptNotification $notification) use ($existing): bool {
            $mail = $notification->toMail($existing);
            $text = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

            return $mail->actionUrl === 'https://app.loopartner.test/login'
                && str_contains($text, 'https://app.loopartner.test/forgot-password');
        });
    });

    it('renvoie toujours les erreurs de validation, qui ne dépendent pas de l\'existence du compte', function (): void {
        User::factory()->create(['email' => 'camille@example.com']);

        $this->postJson('/api/v1/auth/register', registrationPayload(['password' => 'court', 'password_confirmation' => 'court']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password'])
            ->assertJsonMissingValidationErrors(['email']);
    });
});

describe('lien de vérification', function (): void {
    it('vérifie l\'adresse sans être connecté, puis redirige vers la connexion du frontend', function (): void {
        $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);
        $user = User::query()->where('email', 'camille@example.com')->sole();

        $this->get(verificationUrlFor($user))
            ->assertRedirect('https://app.loopartner.test/login?verified=1');

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
        $this->assertGuest();

        // Le compte vérifié peut ensuite se connecter.
        $this->postJson('/api/v1/auth/login', ['email' => 'camille@example.com', 'password' => 'correct-horse-battery-staple'])->assertOk();
        $this->assertAuthenticatedAs($user);
    });

    it('expire au bout de 60 minutes', function (): void {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $url = verificationUrlFor($user);

        $this->travel(61)->minutes();

        $this->get($url)->assertRedirect('https://app.loopartner.test/login?verification=expired');
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('refuse un lien modifié', function (Closure $tamper): void {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $this->get($tamper(verificationUrlFor($user), $user, $other))
            ->assertRedirect('https://app.loopartner.test/login?verification=invalid');

        expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
            ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
    })->with([
        'signature retirée' => [fn (string $url): string => preg_replace('/&signature=[^&]+/', '', $url)],
        'signature falsifiée' => [fn (string $url): string => preg_replace('/signature=[^&]+/', 'signature='.str_repeat('a', 64), $url)],
        'autre utilisateur' => [fn (string $url, User $user, User $other): string => str_replace("/verify/{$user->id}/", "/verify/{$other->id}/", $url)],
        'expiration prolongée' => [fn (string $url): string => preg_replace('/expires=\d+/', 'expires='.(time() + 999999), $url)],
    ]);

    it('refuse un lien signé dont le hash ne correspond pas à l\'adresse', function (): void {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('autre@example.com')]);

        $this->get($url)->assertRedirect('https://app.loopartner.test/login?verification=invalid');
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('ne sert pas de redirection ouverte', function (): void {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $url = verificationUrlFor($user);

        // Un paramètre ajouté invalide la signature ; la destination reste le frontend.
        $this->get($url.'&redirect=https://evil.example/phishing')
            ->assertRedirect('https://app.loopartner.test/login?verification=invalid');
    });

    it('renvoie un lien à l\'utilisateur connecté qui n\'est pas vérifié', function (): void {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')->assertStatus(202);
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $verified = User::factory()->create();
        $this->actingAs($verified)->postJson('/api/v1/auth/email/verification-notification')->assertNoContent();
        Notification::assertNotSentTo($verified, VerifyEmailNotification::class);
    });

    it('exige d\'être connecté pour renvoyer un lien', function (): void {
        $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();
    });
});

describe('mot de passe oublié', function (): void {
    it('répond de façon identique pour une adresse inconnue, connue ou déjà sollicitée', function (): void {
        $user = User::factory()->create(['email' => 'camille@example.com']);

        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnue@example.com']);
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'camille@example.com']);
        // Une seconde demande dans la minute : le broker la refuse (throttle), sans le révéler.
        $throttled = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'camille@example.com']);

        $unknown->assertOk()->assertExactJson([
            'message' => 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'être envoyé.',
        ]);
        expect($known->status())->toBe(200)
            ->and($known->getContent())->toBe($unknown->getContent())
            ->and($throttled->status())->toBe(200)
            ->and($throttled->getContent())->toBe($unknown->getContent());

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        expect(new ResetPasswordNotification('jeton'))->toBeInstanceOf(ShouldQueue::class);
    });

    it('valide toujours le format de l\'adresse', function (): void {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'pas-un-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });
});
