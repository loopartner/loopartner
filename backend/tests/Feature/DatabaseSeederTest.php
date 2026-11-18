<?php

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Accounts\Actions\IsPremium;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Support\ArticleQuota;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\Journey;
use App\Modules\Compliance\Models\Task;
use App\Modules\Compliance\Models\ThresholdAlert;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\SellerProfile;
use Database\Seeders\AppointmentsDemoSeeder;
use Database\Seeders\ComplianceDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MarketplaceDemoSeeder;
use Database\Seeders\ServiceCategorySeeder;
use Database\Seeders\WorkspacesDemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

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

it('crée des événements de démonstration dans chaque état, sans email, et peut être relancé', function (): void {
    allowDemoSeeding();

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $events = Event::query()->get()->keyBy('title');

    expect($events)->toHaveCount(7)
        ->and($events['Atelier : bien démarrer sa comptabilité']->status)->toBe(EventStatus::Published)
        ->and($events['Webinaire : protéger sa marque']->format->value)->toBe('online')
        ->and($events['Conférence : financer sa création d\'entreprise']->format->value)->toBe('hybrid')
        ->and($events['Conférence : financer sa création d\'entreprise']->pendingRevision)->not->toBeNull()
        ->and($events['Salon des jeunes entreprises']->is_free)->toBeFalse()
        ->and($events['Salon des jeunes entreprises']->status)->toBe(EventStatus::Published)
        ->and($events['Networking : entrepreneurs de Lille']->status)->toBe(EventStatus::PendingReview)
        ->and($events['Atelier : devenir riche en une semaine']->status)->toBe(EventStatus::Rejected)
        ->and($events['Atelier : devenir riche en une semaine']->rejection_reason)->toBe(DatabaseSeeder::DEMO_REJECTION_REASON)
        ->and($events['Atelier : choisir son statut juridique']->status)->toBe(EventStatus::Past)
        ->and(EventRegistration::query()->count())->toBe(6)
        ->and($events['Atelier : bien démarrer sa comptabilité']->registrations_count)->toBe(2);

    $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(4, 'data');

    // Aucun email n'est mis en file d'attente pour les données de démonstration.
    expect(DB::table('jobs')->count())->toBe(0);
});

it('crée les articles de démonstration, un prestataire premium et un entrepreneur au quota atteint, sans email', function (): void {
    allowDemoSeeding();

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $articles = Article::query()->get()->keyBy('title');
    $entrepreneur = User::query()->where('email', 'entrepreneur@example.com')->sole();
    $provider = User::query()->where('email', 'prestataire@example.com')->sole();

    expect($articles)->toHaveCount(count(DatabaseSeeder::DEMO_ARTICLES))
        ->and($articles['Bienvenue sur le blog de Loopartner']->status)->toBe(ArticleStatus::Published)
        ->and($articles['Bienvenue sur le blog de Loopartner']->author_kind)->toBe(AuthorKind::Editorial)
        ->and($articles['Cinq conseils pour réussir son identité visuelle']->status)->toBe(ArticleStatus::Published)
        ->and($articles['Cinq conseils pour réussir son identité visuelle']->pendingRevision)->not->toBeNull()
        ->and($articles['Comment rédiger un bon brief pour un prestataire']->status)->toBe(ArticleStatus::PendingReview)
        ->and($articles['Gagner dix mille euros par mois sans effort']->status)->toBe(ArticleStatus::Rejected)
        ->and($articles['Gagner dix mille euros par mois sans effort']->rejection_reason)->toBe(DatabaseSeeder::DEMO_ARTICLE_REJECTION_REASON)
        ->and($articles['Trouver ses premiers clients sans budget']->status)->toBe(ArticleStatus::Draft)
        ->and(app(IsPremium::class)->handle($provider->id))->toBeTrue()
        ->and(ArticleQuota::status($entrepreneur->id))->toBe(['premium' => false, 'limit' => 2, 'used' => 2, 'remaining' => 0]);

    $this->getJson('/api/v1/articles')->assertOk()->assertJsonCount(4, 'data');

    // Aucun email n'est mis en file d'attente pour les données de démonstration.
    expect(DB::table('jobs')->count())->toBe(0);
});

it('active la double authentification de l\'admin de démonstration avec un secret documenté', function (): void {
    allowDemoSeeding();
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('email', 'admin@example.com')->sole();

    expect($admin->two_factor_confirmed_at)->not->toBeNull()
        ->and(decrypt($admin->two_factor_secret))->toBe(DatabaseSeeder::DEMO_ADMIN_TWO_FACTOR_SECRET);

    // Parcours complet : mot de passe, puis code TOTP calculé depuis le secret documenté.
    // L'environnement redevient "testing" (allowDemoSeeding l'a passé en local, ce qui
    // réactiverait la vérification CSRF des requêtes de test).
    app()->detectEnvironment(fn (): string => 'testing');
    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => DatabaseSeeder::LOCAL_PASSWORD])
        ->assertOk()
        ->assertJsonPath('two_factor', true);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp(DatabaseSeeder::DEMO_ADMIN_TWO_FACTOR_SECRET)])
        ->assertSuccessful();

    fromFrontend()->getJson('/api/v1/admin/events/queue')
        ->assertOk()
        ->assertJsonPath('meta.counts', ['event' => 1, 'revision' => 1, 'announcement' => 0]);
});

it('crée le suivi administratif de démonstration avec des règles fictives, sans email, et peut être relancé', function (): void {
    allowDemoSeeding();
    Carbon::setTestNow('2026-11-13 10:00:00');

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $entrepreneur = User::query()->where('email', 'entrepreneur@example.com')->sole();
    $provider = User::query()->where('email', 'prestataire@example.com')->sole();
    $company = Company::query()->where('user_id', $entrepreneur->id)->sole();

    // Règles fictives, vérifiées, toutes marquées comme démonstration ; aucun brouillon réel.
    expect(ComplianceRule::query()->count())->toBeGreaterThan(5)
        ->and(ComplianceRule::query()->where('is_demo', false)->count())->toBe(0)
        ->and(ComplianceRule::query()->where('code', 'not like', 'demo.%')->count())->toBe(0)
        ->and(ComplianceRule::query()->get()->every(fn (ComplianceRule $rule): bool => str_starts_with($rule->title['fr'], '[Démo]')))->toBeTrue();

    // Entrepreneur : calendrier, tâches, parcours, chiffre d'affaires proche du seuil.
    $thresholds = $this->actingAs($entrepreneur)->getJson("/api/v1/me/compliance/companies/{$company->uuid}/thresholds")
        ->assertOk()
        ->json('data.thresholds');
    $ceiling = collect($thresholds)->firstWhere('code', 'demo.micro.ceiling.services');

    expect(Deadline::query()->where('company_id', $company->id)->where('source', 'rule')->count())->toBeGreaterThan(0)
        ->and(Deadline::query()->where('company_id', $company->id)->where('source', 'custom')->count())->toBe(1)
        ->and(Journey::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and(Task::query()->where('company_id', $company->id)->count())->toBeGreaterThan(3)
        ->and($ceiling['ratio_basis_points'])->toBeGreaterThanOrEqual(8000)->toBeLessThan(10_000)
        ->and(ThresholdAlert::query()->where('company_id', $company->id)->where('level', 80)->exists())->toBeTrue();

    // Prestataire premium : deux entreprises.
    expect(Company::query()->where('user_id', $provider->id)->count())->toBe(2);

    // Aucun email mis en file d'attente.
    expect(DB::table('jobs')->count())->toBe(0);
});

it('ne crée aucune règle ni entreprise de démonstration hors du poste local', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(ComplianceRule::query()->count())->toBe(0)
        ->and(Company::query()->count())->toBe(0);

    expect(fn () => $this->seed(ComplianceDemoSeeder::class))->toThrow(RuntimeException::class);
});

it('crée les offres et le devis de démonstration avec un taux fictif, sans email, et peut être relancé', function (): void {
    allowDemoSeeding();
    Carbon::setTestNow('2026-11-17 10:00:00');
    config(['queue.default' => 'database']);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $provider = User::query()->where('email', 'prestataire@example.com')->sole();
    $particulier = User::query()->where('email', 'particulier@example.com')->sole();
    $quote = Quote::query()->sole();

    expect(SellerProfile::query()->where('user_id', $provider->id)->count())->toBe(1)
        ->and(Offer::query()->where('user_id', $provider->id)->where('status', 'published')->count())->toBe(count(MarketplaceDemoSeeder::DEMO_OFFERS))
        ->and(ComplianceRule::query()->where('code', MarketplaceDemoSeeder::DEMO_VAT_CODE)->sole()->is_demo)->toBeTrue()
        ->and($quote->status->value)->toBe('sent')
        ->and($quote->client_id)->toBe($particulier->id)
        ->and($quote->issuer_snapshot['branding']['footer'] ?? null)->toBe('[Démo] Mentions personnalisées du prestataire.');

    $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(count(MarketplaceDemoSeeder::DEMO_OFFERS), 'data');
    $this->actingAs($particulier)->getJson('/api/v1/me/quotes?box=received')->assertOk()->assertJsonCount(1, 'data');

    expect(DB::table('jobs')->count())->toBe(0);
});

it('crée le calendrier et les rendez-vous de démonstration, sans email, et peut être relancé', function (): void {
    allowDemoSeeding();
    Carbon::setTestNow('2026-11-17 10:00:00');
    config(['queue.default' => 'database']);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $provider = User::query()->where('email', 'prestataire@example.com')->sole();
    $particulier = User::query()->where('email', 'particulier@example.com')->sole();
    $slug = (string) DB::table('provider_profiles')->where('user_id', $provider->id)->value('slug');

    expect(DB::table('appointment_calendars')->count())->toBe(1)
        ->and(DB::table('appointment_types')->count())->toBe(count(AppointmentsDemoSeeder::DEMO_TYPES))
        ->and(DB::table('appointments')->orderBy('starts_at')->pluck('status')->all())->toBe(['confirmed', 'proposed']);

    $this->getJson("/api/v1/providers/{$slug}/booking")->assertOk()->assertJsonCount(1, 'data.types');
    $this->actingAs($particulier)->getJson('/api/v1/me/appointments')->assertOk()->assertJsonCount(2, 'data');

    expect(DB::table('jobs')->count())->toBe(0);
});

it('refuse les rendez-vous de démonstration hors du poste local', function (): void {
    expect(fn () => $this->seed(AppointmentsDemoSeeder::class))->toThrow(RuntimeException::class);
});

it('crée les workspaces de démonstration, sans email ni appel à l\'IA, et peut être relancé', function (): void {
    allowDemoSeeding();
    config(['queue.default' => 'database']);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $particulier = User::query()->where('email', 'particulier@example.com')->sole();
    $entrepreneur = User::query()->where('email', 'entrepreneur@example.com')->sole();

    expect(DB::table('workspaces')->count())->toBe(2)
        ->and(DB::table('workspace_copilot_suggestions')->count())->toBe(1)
        ->and(DB::table('ai_usages')->count())->toBe(0);

    $workspace = $this->actingAs($particulier)->getJson('/api/v1/me/workspaces')->assertOk()->assertJsonCount(1, 'data')->json('data.0.uuid');
    $this->actingAs($particulier)->getJson('/api/v1/me/workspaces/'.$workspace)
        ->assertOk()
        ->assertJsonPath('data.title', WorkspacesDemoSeeder::DEMO_WORKSPACE_TITLE)
        ->assertJsonPath('data.steps.1.ai_generated', true)
        ->assertJsonPath('data.progress.tasks_done', 1);
    $this->actingAs($particulier)->getJson('/api/v1/me/copilot')->assertJsonPath('data.quota.roadmaps.used', 1);
    $this->actingAs($entrepreneur)->getJson('/api/v1/me/workspaces')->assertJsonPath('data.0.template', 'lancer-son-activite');

    expect(DB::table('jobs')->count())->toBe(0);
});

it('refuse les workspaces de démonstration hors du poste local', function (): void {
    expect(fn () => $this->seed(WorkspacesDemoSeeder::class))->toThrow(RuntimeException::class);
});
