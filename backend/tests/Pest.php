<?php

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
| par défaut qu'aucun mot de passe n'est compromis.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response(''),
        ]);
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
