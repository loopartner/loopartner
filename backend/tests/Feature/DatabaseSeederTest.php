<?php

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Support\Carbon;
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
        ->and($profile->slug)->toBe('prestataire-test')
        // Un profil publié doit avoir au moins une catégorie.
        ->and($profile->categories()->pluck('slug')->all())
        ->toEqualCanonicalizing(['creation-statuts', 'developpement-web', 'identite-visuelle']);

    $this->getJson('/api/v1/providers/prestataire-test')->assertOk();
    $this->getJson('/api/v1/providers?category=developpement-web')->assertOk()->assertJsonPath('data.0.slug', 'prestataire-test');
});

it('donne un projet publié et un brouillon au particulier et à l\'entrepreneur de démonstration', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Project::query()->count())->toBe(4);

    foreach (['particulier', 'entrepreneur'] as $role) {
        $projects = Project::query()->whereRelation('user', 'email', "{$role}@example.com")->get();

        expect($projects)->toHaveCount(2)
            ->and($projects->filter(fn (Project $project): bool => $project->isLive()))->toHaveCount(1)
            ->and($projects->where('status', ProjectStatus::Draft))->toHaveCount(1);
    }

    $provider = User::query()->where('email', 'prestataire@example.com')->sole();
    $this->actingAs($provider)->getJson('/api/v1/projects')->assertOk()->assertJsonCount(2, 'data');
});

it('republie un projet de démonstration expiré', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    $this->seed(DatabaseSeeder::class);

    Carbon::setTestNow(now()->addDays(Project::PUBLICATION_DAYS + 1));
    $this->artisan('projects:close-expired')->assertSuccessful();
    expect(Project::query()->live()->count())->toBe(0);

    $this->seed(DatabaseSeeder::class);

    expect(Project::query()->count())->toBe(4)
        ->and(Project::query()->live()->count())->toBe(2);
});

it('crée les catégories, mais aucun utilisateur ni projet, hors de l\'environnement local', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(0)
        ->and(ServiceCategory::query()->whereNull('parent_id')->count())->toBe(count(ServiceCategorySeeder::CATEGORIES));
});
