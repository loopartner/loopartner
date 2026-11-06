<?php

dataset('urls', [
    'health check' => '/up',
    'route API inconnue' => '/api/v1/route-inconnue',
    'cookie CSRF Sanctum' => '/sanctum/csrf-cookie',
]);

it('ajoute les en-têtes de sécurité', function (string $url): void {
    $this->get($url)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
})->with('urls');
