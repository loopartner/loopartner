<?php

it('renvoie une erreur 404 en JSON pour une route API inconnue, même sans en-tête Accept', function (): void {
    $this->get('/api/v1/route-inconnue')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});

it('renvoie les erreurs de validation en JSON', function (): void {
    $this->post('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonValidationErrors(['email', 'password']);
});
