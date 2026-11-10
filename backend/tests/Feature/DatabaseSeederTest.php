<?php

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('crée un utilisateur vérifié par rôle en local et peut être relancé', function (): void {
    allowDemoSeeding();

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
    allowDemoSeeding();

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

it('crée une mise en relation de démonstration, sans email, et peut être relancé', function (): void {
    allowDemoSeeding();
    // Driver de production : un email mis en file d'attente laisserait une ligne dans jobs.
    config(['queue.default' => 'database']);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $provider = User::query()->where('email', 'prestataire@example.com')->sole();
    $particulier = User::query()->where('email', 'particulier@example.com')->sole();
    $entrepreneur = User::query()->where('email', 'entrepreneur@example.com')->sole();

    expect(MatchRequest::query()->count())->toBe(3);

    $accepted = MatchRequest::query()->where('status', MatchRequestStatus::Accepted->value)->sole();
    expect($accepted->type)->toBe(MatchRequestType::Proposal)
        ->and($accepted->author_id)->toBe($provider->id)
        ->and($accepted->recipient_id)->toBe($particulier->id);

    $pending = MatchRequest::query()->where('status', MatchRequestStatus::Pending->value)->sole();
    expect($pending->type)->toBe(MatchRequestType::Proposal)
        ->and($pending->recipient_id)->toBe($entrepreneur->id);

    $declined = MatchRequest::query()->where('status', MatchRequestStatus::Declined->value)->sole();
    expect($declined->type)->toBe(MatchRequestType::Contact)
        ->and($declined->author_id)->toBe($entrepreneur->id)
        ->and($declined->recipient_id)->toBe($provider->id);

    $conversation = Conversation::query()->sole();
    expect($conversation->match_request_id)->toBe($accepted->id)
        ->and($conversation->messages()->count())->toBe(count(DatabaseSeeder::DEMO_MESSAGES));

    $this->actingAs($particulier)->getJson('/api/v1/me/conversations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.unread_count', 1);
    $this->actingAs($provider)->getJson('/api/v1/me/match-requests?box=received')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'declined');

    // Aucun email n'est mis en file d'attente pour les données de démonstration.
    expect(DB::table('jobs')->count())->toBe(0);
});

it('republie un projet de démonstration expiré', function (): void {
    allowDemoSeeding();
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
