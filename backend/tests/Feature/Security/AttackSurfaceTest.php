<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
| Surface d'attaque : pas de route inutile, pas de page HTML, et des réponses JSON sur toute
| l'API, même quand le client n'envoie pas "Accept: application/json".
*/

it('ne sert pas le disque privé "local" par des routes signées', function (): void {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse();

    $this->put('/storage/fichier.txt?upload=1', ['contenu'])->assertNotFound();
});

it('répond en JSON à la racine, sans page HTML de Laravel', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['name' => 'Loopartner API']);
});

it('répond en JSON sur l\'API sans en-tête Accept', function (Closure $request, int $status): void {
    $response = $request($this);

    $response->assertStatus($status)->assertHeader('Content-Type', 'application/json');
})->with([
    'route 2FA sans mot de passe confirmé (423)' => [
        fn ($test) => $test->actingAs(User::factory()->create())->get('/api/v1/auth/user/two-factor-qr-code'),
        423,
    ],
    'route réservée aux emails vérifiés (403)' => [
        fn ($test) => $test->actingAs(projectOwner('prestataire', verified: false))->get('/api/v1/projects'),
        403,
    ],
    'route protégée sans session (401)' => [
        fn ($test) => $test->get('/api/v1/me'),
        401,
    ],
]);
