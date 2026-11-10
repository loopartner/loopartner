<?php

use App\Models\ServiceCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataGuard;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

/*
| Les comptes de démonstration (dont un admin au mot de passe "password") ne doivent jamais
| pouvoir être créés hors d'un poste de développement, même si APP_ENV est mal configuré.
*/

beforeEach(function (): void {
    Role::query()->delete();
});

it('ne crée aucun compte en local sans SEED_DEMO_DATA', function (): void {
    allowDemoSeeding();
    config(['app.seed_demo_data' => false]);

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBe(count(RoleSeeder::ROLES));
});

it('refuse SEED_DEMO_DATA quand une condition locale manque', function (Closure $breakCondition, string $expectedMessage): void {
    allowDemoSeeding();
    $breakCondition();

    // Appel direct : en production, la commande db:seed demanderait une confirmation.
    expect(fn () => app(DatabaseSeeder::class)->setContainer(app())->__invoke())
        ->toThrow(RuntimeException::class, $expectedMessage);

    expect(User::query()->count())->toBe(0);
})->with([
    'APP_ENV=production' => [fn () => app()->detectEnvironment(fn (): string => 'production'), 'APP_ENV doit valoir "local"'],
    'APP_ENV=staging' => [fn () => app()->detectEnvironment(fn (): string => 'staging'), 'APP_ENV doit valoir "local"'],
    'APP_DEBUG=false' => [fn () => config(['app.debug' => false]), 'APP_DEBUG doit valoir true'],
    'APP_URL publique' => [fn () => config(['app.url' => 'https://api.loopartner.fr']), 'APP_URL doit pointer vers un hôte local'],
    'APP_URL qui contient localhost' => [fn () => config(['app.url' => 'https://localhost.evil.fr']), 'APP_URL doit pointer vers un hôte local'],
]);

it('reconnaît les URL locales', function (string $url, bool $expected): void {
    expect(DemoDataGuard::isLocalUrl($url))->toBe($expected);
})->with([
    ['http://localhost:8000', true],
    ['http://127.0.0.1', true],
    ['http://[::1]:8000', true],
    ['http://loopartner.test', true],
    ['http://api.loopartner.localhost', true],
    ['https://api.loopartner.fr', false],
    ['https://localhost.loopartner.fr', false],
    ['', false],
]);

it('crée les comptes de démonstration quand toutes les conditions sont réunies', function (): void {
    allowDemoSeeding();

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'admin@example.com')->exists())->toBeTrue();
});

it('ne crée que les rôles et les catégories avec le seeder de production', function (): void {
    // Même avec une configuration locale complète : ReferenceDataSeeder ne crée jamais d'utilisateur.
    allowDemoSeeding();

    $this->seed(ReferenceDataSeeder::class);

    expect(User::query()->count())->toBe(0)
        ->and(Role::query()->pluck('name')->sort()->values()->all())->toBe(collect(RoleSeeder::ROLES)->sort()->values()->all())
        ->and(ServiceCategory::query()->count())->toBeGreaterThan(0);
});
