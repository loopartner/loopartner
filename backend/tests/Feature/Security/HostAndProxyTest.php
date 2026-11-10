<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Notification;

/*
| En-têtes Host et X-Forwarded-* : les liens envoyés par email utilisent toujours APP_URL, et
| l'IP du client n'est lue dans X-Forwarded-For que derrière un proxy déclaré (TRUSTED_PROXIES).
*/

it('construit le lien de vérification avec APP_URL, même avec un en-tête Host forgé', function (): void {
    Notification::fake();

    // URL absolue : la requête de test porte alors "Host: evil.example".
    $this->postJson('http://evil.example/api/v1/auth/register', registrationPayload())
        ->assertStatus(202);

    $url = verificationUrlFor(User::query()->sole());

    expect($url)->toStartWith(rtrim((string) config('app.url'), '/').'/api/v1/auth/email/verify/')
        ->and($url)->not->toContain('evil.example');
});

it('ignore X-Forwarded-For quand aucun proxy n\'est déclaré', function (): void {
    config(['security.trusted_proxies' => '']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', '203.0.113.5')
        ->postJson('/api/v1/auth/login', ['email' => 'camille@example.com', 'password' => 'mauvais-mot-de-passe'])
        ->assertUnprocessable();

    expect(securityLog('auth.login_failed')[0]->context['ip'])->toBe('10.0.0.1');
});

it('lit l\'IP du client dans X-Forwarded-For derrière un proxy déclaré', function (string $trustedProxies): void {
    config(['security.trusted_proxies' => $trustedProxies]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', '203.0.113.5')
        ->postJson('/api/v1/auth/login', ['email' => 'camille@example.com', 'password' => 'mauvais-mot-de-passe'])
        ->assertUnprocessable();

    expect(securityLog('auth.login_failed')[0]->context['ip'])->toBe('203.0.113.5');
})->with([
    'adresse exacte' => '10.0.0.1',
    'plage CIDR, liste avec espaces' => '192.0.2.0/24, 10.0.0.0/8',
]);

it('ignore X-Forwarded-For envoyé par un proxy non déclaré', function (): void {
    config(['security.trusted_proxies' => '192.0.2.10']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', '203.0.113.5')
        ->postJson('/api/v1/auth/login', ['email' => 'camille@example.com', 'password' => 'mauvais-mot-de-passe'])
        ->assertUnprocessable();

    expect(securityLog('auth.login_failed')[0]->context['ip'])->toBe('10.0.0.1');
});

it('n\'accepte que l\'hôte de APP_URL hors environnement local', function (): void {
    expect(app(Kernel::class)->hasMiddleware(TrustHosts::class))->toBeTrue();
});
