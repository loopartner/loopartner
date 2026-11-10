<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configuration des tests
|--------------------------------------------------------------------------
|
| Les tests Feature utilisent SQLite en mémoire (phpunit.xml) et une base
| réinitialisée à chaque test. Aucun appel HTTP sortant n'est autorisé :
| l'API Have I Been Pwned (règle "uncompromised") est simulée et répond
| par défaut qu'aucun mot de passe n'est compromis. Les rôles sont créés
| par RoleSeeder avant chaque test.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response(''),
        ]);

        // Les rôles de la plateforme existent dans toutes les bases de test.
        $this->seed(RoleSeeder::class);
    })
    ->in('Feature');

/**
 * Simule l'API Have I Been Pwned en signalant le mot de passe donné comme compromis.
 */
function fakeCompromisedPassword(string $password): void
{
    $hash = strtoupper(sha1($password));

    // Le premier stub déclaré l'emporte : on repart d'un client vierge pour remplacer celui du beforeEach.
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':12345'),
    ]);
}

/**
 * Données d'inscription valides, que chaque test peut surcharger.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Camille Martin',
        'email' => 'camille@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'account_type' => 'particulier',
    ], $overrides);
}
