<?php

dataset('urls', [
    'health check' => '/up',
    'route API inconnue' => '/api/v1/route-inconnue',
    'cookie CSRF Sanctum' => '/sanctum/csrf-cookie',
    'route API' => '/api/v1/categories',
]);

it('ajoute les en-têtes de sécurité', function (string $url): void {
    $this->get($url)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
})->with('urls');

it('n\'envoie pas HSTS hors production', function (): void {
    $this->get('https://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
});

it('n\'envoie pas HSTS en production sur une requête HTTP', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
});

it('envoie HSTS en production sur une requête HTTPS', function (string $url): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('https://localhost'.$url)
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
})->with('urls');
