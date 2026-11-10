<?php

use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

it('crée un utilisateur vérifié par rôle en local et peut être relancé', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(5);

    foreach (['particulier', 'prestataire', 'entrepreneur', 'redacteur', 'admin'] as $role) {
        $user = User::query()->where('email', "{$role}@example.com")->sole();

        expect($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->getRoleNames()->all())->toBe([$role])
            ->and(Hash::check(DatabaseSeeder::LOCAL_PASSWORD, $user->password))->toBeTrue();
    }

    $profile = ProviderProfile::query()->sole();
    expect($profile->user->email)->toBe('prestataire@example.com')
        ->and($profile->isPublished())->toBeTrue()
        ->and($profile->slug)->toBe('prestataire-test');

    $this->getJson('/api/v1/providers/prestataire-test')->assertOk();
});

it('ne crée aucun utilisateur hors de l\'environnement local', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0);
});
