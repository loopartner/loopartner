<?php

use App\Models\LegalDocument;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Accounts\Models\PremiumGrant;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\AvailabilityRule;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleQuotaEntry;
use App\Modules\Blog\Support\Authors;
use App\Modules\Compliance\Actions\CreateCompany;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Directory\Actions\AcceptMatchRequest;
use App\Modules\Directory\Actions\SendMessage;
use App\Modules\Directory\Actions\SendProposal;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Message;
use App\Modules\Directory\Models\Project;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\SellerProfile;
use App\Modules\Workspaces\Models\Workspace;
use App\Notifications\VerifyEmailNotification;
use App\Support\Legal\LegalDocuments;
use App\Support\Moderation\ModerationTargets;
use App\Support\TwoFactorSession;
use Database\Factories\Modules\Blog\ArticleFactory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
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

        // Le journal de sécurité est gardé en mémoire (voir securityLog()) : aucun fichier écrit.
        config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Journal de sécurité
|--------------------------------------------------------------------------
*/

/**
 * Change le schéma au milieu d'un test (migration rejouée, table temporaire).
 *
 * MySQL et MariaDB valident implicitement la transaction en cours à chaque instruction DDL :
 * la transaction de RefreshDatabase disparaît, Laravel croit pourtant en avoir une, et le
 * premier DB::transaction() du test échoue (« SAVEPOINT trans2 does not exist ») au lieu de se
 * comporter comme en production. On rouvre donc une vraie transaction après le changement, et
 * la base est recréée avant le test suivant, puisque les lignes écrites avant ont été validées.
 * SQLite exécute le DDL dans la transaction : rien à faire.
 *
 * @template TResult
 *
 * @param  Closure(): TResult  $change
 * @return TResult
 */
function changeSchemaInTest(Closure $change): mixed
{
    $connection = DB::connection();

    if ($connection->getDriverName() === 'sqlite') {
        return $change();
    }

    try {
        return $change();
    } finally {
        RefreshDatabaseState::$migrated = false;

        if (! $connection->getPdo()->inTransaction()) {
            $connection->getPdo()->beginTransaction();
        }
    }
}

/**
 * Entrées du journal de sécurité (canal "security"), éventuellement filtrées par événement.
 *
 * @return list<LogRecord>
 */
function securityLog(?string $event = null): array
{
    /** @var Logger $logger */
    $logger = Log::channel('security')->getLogger();
    /** @var TestHandler $handler */
    $handler = $logger->getHandlers()[0];

    return array_values(array_filter(
        $handler->getRecords(),
        fn (LogRecord $record): bool => $event === null || $record->message === $event,
    ));
}

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
        'legal_documents' => legalDocumentsInForce(),
    ], $overrides);
}

/**
 * Versions en vigueur des CGU et de la politique de confidentialité ({"terms": …, "privacy": …}),
 * créées si la base de test n'en a pas encore (l'inscription les exige).
 *
 * @return array<string, string>
 */
function legalDocumentsInForce(): array
{
    $current = LegalDocuments::current();

    if (! isset($current['terms'])) {
        $current['terms'] = LegalDocument::factory()->create(['version' => 'cgu-test-1']);
    }

    if (! isset($current['privacy'])) {
        $current['privacy'] = LegalDocument::factory()->privacy()->create(['version' => 'confidentialite-test-1']);
    }

    return ['terms' => $current['terms']->version, 'privacy' => $current['privacy']->version];
}

/**
 * URL du lien de vérification envoyé à l'utilisateur (Notification::fake() doit être actif).
 */
function verificationUrlFor(User $user): string
{
    $url = null;

    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user, &$url): bool {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    return (string) $url;
}

/*
|--------------------------------------------------------------------------
| Double authentification
|--------------------------------------------------------------------------
*/

/**
 * Secret TOTP (base32) des utilisateurs de test ayant la double authentification.
 */
const TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';

/**
 * Utilisateur dont la double authentification est activée et confirmée.
 */
function userWithTwoFactor(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->forceFill([
        'two_factor_secret' => encrypt(TWO_FACTOR_SECRET),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-de-secours-1', 'code-de-secours-2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

/**
 * Requête venant du frontend (SPA) : Sanctum n'ouvre une session sur les routes de l'API que
 * pour un domaine déclaré dans sanctum.stateful (en-tête Referer ou Origin). Indispensable pour
 * tester une donnée de session (withSession) sur une route /api/v1 hors Fortify.
 */
function fromFrontend(): TestCase
{
    config(['sanctum.stateful' => ['localhost:3000']]);

    return test()->withHeader('Referer', 'http://localhost:3000/');
}

/**
 * Admin dont la double authentification est activée et confirmée.
 */
function moderator(array $attributes = []): User
{
    $admin = userWithTwoFactor($attributes);
    $admin->assignRole('admin');

    return $admin;
}

/**
 * Requête d'un admin dont la session a été ouverte avec un code 2FA (accès à la modération).
 */
function asModerator(?User $admin = null): TestCase
{
    return fromFrontend()
        ->actingAs($admin ?? moderator())
        ->withSession([TwoFactorSession::KEY => time()]);
}

/*
|--------------------------------------------------------------------------
| Données de démonstration
|--------------------------------------------------------------------------
*/

/**
 * Réunit toutes les conditions de DemoDataGuard : environnement local, debug, URL locale et
 * SEED_DEMO_DATA=true. Chaque test peut ensuite en retirer une.
 */
function allowDemoSeeding(): void
{
    app()->detectEnvironment(fn (): string => 'local');
    config([
        'app.seed_demo_data' => true,
        'app.debug' => true,
        'app.url' => 'http://localhost:8000',
    ]);
}

/*
|--------------------------------------------------------------------------
| Images de test (brique média)
|--------------------------------------------------------------------------
|
| Les images sont générées par GD dans un fichier temporaire, puis enveloppées dans un
| vrai UploadedFile (et non UploadedFile::fake()) : leur type est ainsi détecté sur leur
| contenu, comme en production.
|
*/

/**
 * Crée une image réelle du format donné (jpeg, png, webp, gif).
 */
function testImage(string $format, int $width = 600, int $height = 600, ?string $clientName = null): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($image, 200, 40, 40));

    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($image),
        'png' => imagepng($image),
        'webp' => imagewebp($image),
        'gif' => imagegif($image),
    };
    $contents = (string) ob_get_clean();

    return uploadedFileWithContents($contents, $clientName ?? 'photo.'.($format === 'jpeg' ? 'jpg' : $format));
}

/**
 * Crée un fichier envoyé avec le contenu exact donné.
 */
function uploadedFileWithContents(string $contents, string $clientName): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'media-test-');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $clientName, null, null, true);
}

/**
 * JPEG contenant un bloc EXIF (description et coordonnées GPS) et des données cachées après
 * la fin de l'image. Les marqueurs permettent de vérifier qu'ils ont disparu du fichier stocké.
 */
function jpegWithMetadata(string $exifMarker, string $hiddenMarker): UploadedFile
{
    $image = imagecreatetruecolor(600, 400);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    // TIFF little-endian : IFD0 (ImageDescription + pointeur GPS), puis IFD GPS (latitude).
    $description = $exifMarker."\0";
    if (strlen($description) % 2 === 1) {
        $description .= "\0";
    }
    $ifd0Offset = 8;
    $descriptionOffset = $ifd0Offset + 2 + 2 * 12 + 4;
    $gpsOffset = $descriptionOffset + strlen($description);
    $latitudeOffset = $gpsOffset + 2 + 2 * 12 + 4;

    $entry = fn (int $tag, int $type, int $count, string $value): string => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");

    $tiff = 'II'.pack('vV', 42, $ifd0Offset)
        .pack('v', 2)
        .$entry(0x010E, 2, strlen($exifMarker) + 1, pack('V', $descriptionOffset))
        .$entry(0x8825, 4, 1, pack('V', $gpsOffset))
        .pack('V', 0)
        .$description
        .pack('v', 2)
        .$entry(0x0001, 2, 2, "N\0")
        .$entry(0x0002, 5, 3, pack('V', $latitudeOffset))
        .pack('V', 0)
        .pack('V6', 48, 1, 51, 1, 24, 1);

    $app1 = "Exif\0\0".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

    return uploadedFileWithContents(substr($jpeg, 0, 2).$segment.substr($jpeg, 2).$hiddenMarker, 'vacances.jpg');
}

/*
|--------------------------------------------------------------------------
| Catégories de services
|--------------------------------------------------------------------------
*/

/**
 * Crée une catégorie de services (nom anglais : nom français suivi de " (en)" par défaut).
 */
function serviceCategory(string $slug, ?string $fr = null, ?string $en = null, ?ServiceCategory $parent = null, int $position = 0, bool $active = true): ServiceCategory
{
    $fr ??= ucfirst(str_replace('-', ' ', $slug));

    return ServiceCategory::factory()->create([
        'slug' => $slug,
        'name' => ['fr' => $fr, 'en' => $en ?? $fr.' (en)'],
        'parent_id' => $parent?->id,
        'position' => $position,
        'is_active' => $active,
    ]);
}

/*
|--------------------------------------------------------------------------
| Projets (module Directory)
|--------------------------------------------------------------------------
*/

/**
 * Utilisateur pouvant (ou non, selon le rôle) gérer des projets.
 */
function projectOwner(string $role, bool $verified = true): User
{
    $factory = User::factory();
    $user = ($verified ? $factory : $factory->unverified())->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Prestataire autorisé à consulter les projets : email vérifié et profil publié avec une catégorie.
 */
function browsingProvider(): User
{
    $user = projectOwner('prestataire');

    $profile = ProviderProfile::factory()->published()->create(['user_id' => $user->id]);
    $profile->categories()->attach(ServiceCategory::factory()->create());

    return $user;
}

/**
 * @return array<string, mixed>
 */
function projectPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Création de mon site vitrine',
        'description' => "Je lance mon activité de céramiste et j'ai besoin d'un site pour présenter mes créations.",
        'category' => 'site-vitrine',
        'budget_min' => 1500,
        'budget_max' => 3000,
        'country' => 'FR',
        'city' => 'Lyon',
        'remote_ok' => true,
        'desired_start_date' => '2026-12-01',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Mise en relation et messagerie (module Directory)
|--------------------------------------------------------------------------
*/

/**
 * Projet publié et non expiré d'un porteur de projet (particulier par défaut).
 */
function publishedProjectOf(?User $owner = null): Project
{
    return Project::factory()->published()->create(['user_id' => ($owner ?? projectOwner('particulier'))->id]);
}

/**
 * Slug public du profil d'un prestataire créé par browsingProvider().
 */
function providerSlug(User $provider): string
{
    return ProviderProfile::query()->where('user_id', $provider->id)->sole()->slug;
}

/**
 * @return array<string, mixed>
 */
function proposalPayload(array $overrides = []): array
{
    return array_merge([
        'message' => 'Bonjour, je peux réaliser votre site vitrine en trois semaines, maquettes comprises.',
        'price_amount' => 2500,
        'price_currency' => 'EUR',
    ], $overrides);
}

/**
 * Proposition en attente d'un prestataire sur un projet publié (par l'Action, sans HTTP).
 */
function pendingProposal(?User $provider = null, ?Project $project = null): MatchRequest
{
    return app(SendProposal::class)->handle($provider ?? browsingProvider(), $project ?? publishedProjectOf(), [
        'message' => 'Bonjour, je peux réaliser votre site vitrine en trois semaines.',
        'price_amount' => 2500,
        'price_currency' => 'EUR',
    ]);
}

/**
 * Proposition acceptée et sa conversation : [porteur de projet, prestataire, conversation].
 *
 * @return array{0: User, 1: User, 2: Conversation}
 */
function acceptedConversation(?User $owner = null, ?User $provider = null): array
{
    $owner ??= projectOwner('particulier');
    $provider ??= browsingProvider();

    $matchRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf($owner)));

    /** @var Conversation $conversation */
    $conversation = $matchRequest->conversation;

    return [$owner, $provider, $conversation];
}

/**
 * Vérifie récursivement qu'une réponse ne contient ni identifiant interne ni coordonnées.
 *
 * @param  array<mixed>  $data
 */
function expectNoPrivateFields(array $data): void
{
    $forbidden = ['id', 'user_id', 'author_id', 'recipient_id', 'project_id', 'sender_id', 'conversation_id',
        'match_request_id', 'reporter_id', 'reported_user_id', 'blocker_id', 'blocked_id', 'email', 'phone',
        'active_key', 'content_snapshot', 'last_read_message_id'];

    array_walk_recursive($data, function (mixed $value, string|int $key) use ($forbidden): void {
        expect($forbidden)->not->toContain($key);
    });

    // array_walk_recursive ne visite que les feuilles : on vérifie aussi les clés des tableaux.
    foreach ($data as $key => $value) {
        expect($forbidden)->not->toContain($key);

        if (is_array($value)) {
            expectNoPrivateFields($value);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Événements (module Events)
|--------------------------------------------------------------------------
*/

/**
 * Organisateur d'événements : prestataire avec un profil publié, ou utilisateur vérifié du rôle donné.
 */
function eventOrganizer(string $role = 'entrepreneur'): User
{
    return $role === 'prestataire' ? browsingProvider() : projectOwner($role);
}

/**
 * Données valides d'un événement en présentiel, dans 10 jours à 18 h 30 (heure de Paris).
 *
 * @return array<string, mixed>
 */
function eventPayload(array $overrides = []): array
{
    $start = now()->setTimezone('Europe/Paris')->addDays(10)->setTime(18, 30);

    return array_merge([
        'title' => 'Atelier : bien démarrer sa comptabilité',
        'description' => 'Un atelier pratique de deux heures pour mettre en place la comptabilité de sa jeune entreprise.',
        'type' => 'workshop',
        'format' => 'in_person',
        'language' => 'fr',
        'organizer_name' => 'Collectif des indépendants',
        'venue_name' => 'La Cordée',
        'address_line' => '12 rue de la République',
        'postal_code' => '69001',
        'city' => 'Lyon',
        'country' => 'FR',
        'starts_at' => $start->format('Y-m-d\TH:i'),
        'ends_at' => $start->copy()->addHours(2)->format('Y-m-d\TH:i'),
        'timezone' => 'Europe/Paris',
    ], $overrides);
}

/**
 * Événement publié et à venir d'un organisateur (entrepreneur par défaut).
 *
 * @param  array<string, mixed>  $attributes
 */
function publishedEventOf(?User $organizer = null, array $attributes = []): Event
{
    return Event::factory()->published()->create(['user_id' => ($organizer ?? eventOrganizer())->id, ...$attributes]);
}

/**
 * Inscription active d'un utilisateur à un événement (sans HTTP ni email).
 */
function registerTo(Event $event, ?User $user = null): EventRegistration
{
    $registration = new EventRegistration;
    $registration->forceFill([
        'uuid' => (string) Str::uuid(),
        'event_id' => $event->id,
        'user_id' => ($user ?? projectOwner('particulier'))->id,
        'status' => RegistrationStatus::Registered,
    ])->save();

    $event->increment('registrations_count');

    return $registration;
}

/**
 * Message envoyé par l'Action (sans HTTP ni limite de débit).
 */
function postMessage(User $sender, Conversation $conversation, string $body = 'Bonjour, voici quelques précisions sur le projet.'): Message
{
    return app(SendMessage::class)->handle($sender, $conversation->fresh(['participants.user']), $body);
}

/*
|--------------------------------------------------------------------------
| Blog
|--------------------------------------------------------------------------
*/

/**
 * Auteur d'articles avec un email vérifié : prestataire avec un profil publié, entrepreneur, ou
 * membre de l'équipe (rédacteur, admin) avec la double authentification confirmée.
 */
function articleAuthor(string $role = 'entrepreneur'): User
{
    if ($role === 'prestataire') {
        return browsingProvider();
    }

    if (in_array($role, ['redacteur', 'admin'], true)) {
        $user = userWithTwoFactor();
        $user->assignRole($role);

        return $user;
    }

    return projectOwner($role);
}

/**
 * Requête d'un auteur, avec une session ouverte par un code 2FA (équipe).
 */
function asAuthor(User $user): TestCase
{
    return fromFrontend()->actingAs($user)->withSession([TwoFactorSession::KEY => time()]);
}

/**
 * Catégorie active utilisée par les articles des tests.
 */
function blogCategory(): ServiceCategory
{
    return ServiceCategory::query()->where('slug', 'creation-entreprise')->first()
        ?? serviceCategory('creation-entreprise', 'Création d\'entreprise', 'Starting a business');
}

/**
 * Données complètes d'un article (prêt à être soumis). Le nom d'auteur n'est envoyé que pour
 * un entrepreneur (il est interdit aux autres).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function articlePayload(array $overrides = [], bool $withAuthorName = true): array
{
    return [
        'title' => 'Bien choisir le statut juridique de son entreprise',
        'excerpt' => 'Micro-entreprise, SASU ou EURL : les critères qui comptent vraiment pour bien démarrer son activité.',
        'language' => 'fr',
        'categories' => [blogCategory()->slug],
        'body' => ArticleFactory::body(),
        ...($withAuthorName ? ['author_name' => 'Camille, fondatrice'] : []),
        ...$overrides,
    ];
}

/**
 * Article d'un auteur, complet, dans l'état demandé (draft, pending, published), avec une
 * catégorie. Le type d'auteur suit son rôle.
 *
 * @param  array<string, mixed>  $attributes
 */
function articleOf(User $author, string $state = 'draft', array $attributes = []): Article
{
    $factory = Article::factory()->for($author, 'user');
    $factory = match (Authors::kindOf($author)) {
        AuthorKind::Editorial => $factory->editorial(),
        AuthorKind::Provider => $factory->provider(),
        default => $factory,
    };
    $factory = match ($state) {
        'pending' => $factory->pending(),
        'published' => $factory->published(),
        default => $factory,
    };

    $article = $factory->create($attributes);
    $article->categories()->attach(blogCategory());

    if ($state !== 'draft' && ! $article->author_kind->isEditorial()) {
        ArticleQuotaEntry::query()->forceCreate(['user_id' => $author->id, 'article_uuid' => $article->uuid, 'counted_at' => now()]);
    }

    return $article;
}

function makePremium(User $user, array $attributes = []): PremiumGrant
{
    return PremiumGrant::factory()->for($user)->create($attributes);
}

/*
|--------------------------------------------------------------------------
| Suivi administratif (module Compliance)
|--------------------------------------------------------------------------
| Les règles créées ici sont des règles de TEST, aux valeurs fictives : elles ne décrivent
| aucune règle réelle.
*/

/**
 * Utilisateur du suivi administratif (prestataire ou entrepreneur), email vérifié par défaut.
 */
function complianceUser(string $role = 'entrepreneur', bool $verified = true): User
{
    $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Statut juridique vérifié, en vigueur depuis le 1er janvier 2026, sans date de fin.
 *
 * @param  list<string>  $options
 */
function verifiedLegalForm(string $code = 'fr.legal_form.micro', array $options = ['declaration_frequency', 'income_tax_withholding']): ComplianceRule
{
    return ComplianceRule::factory()->verified()
        ->legalForm($code, ['services_commercial', 'sales', 'services_liberal'], ['franchise', 'simplified'], $options)
        ->create();
}

/**
 * Règle d'échéance vérifiée, en vigueur depuis le 1er janvier 2026.
 *
 * @param  array<string, mixed>  $schedule
 * @param  array<string, mixed>  $conditions
 * @param  list<array<string, mixed>>  $tasks
 * @param  array<string, mixed>  $attributes
 */
function verifiedDeadlineRule(string $code, array $schedule, array $conditions = [], array $tasks = [], array $attributes = []): ComplianceRule
{
    return ComplianceRule::factory()->verified()->deadline($code, $schedule, $conditions, $tasks)->create($attributes);
}

/**
 * Taux de TVA vérifié (module Marketplace), en vigueur depuis le 1er janvier 2026. Valeurs
 * fictives de test : les vrais taux sont des règles vérifiées par un professionnel.
 *
 * @param  array<string, mixed>  $attributes
 */
function verifiedVatRate(string $code = 'fr.vat.rate.standard', int $basisPoints = 2000, bool $exclusive = false, ?array $mention = null, ?array $clientCertification = null, array $attributes = []): ComplianceRule
{
    return ComplianceRule::factory()->verified()
        ->vatRate($code, $basisPoints, $exclusive, $mention, $clientCertification)
        ->create(['valid_from' => '2026-01-01', 'valid_until' => null, ...$attributes]);
}

/**
 * Réponses valides au questionnaire de démarrage.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyPayload(array $overrides = []): array
{
    return [
        'name' => 'Atelier Camille',
        'country' => 'FR',
        'legal_form' => 'fr.legal_form.micro',
        'activity_types' => ['services_commercial'],
        'vat_regime' => 'franchise',
        'created_on' => '2026-03-01',
        'options' => ['declaration_frequency' => 'quarterly'],
        'registration_number' => '123 456 789 00012',
        ...$overrides,
    ];
}

/**
 * Entreprise créée par l'Action (questionnaire), avec son calendrier. Le statut juridique
 * demandé doit exister (verifiedLegalForm()).
 *
 * @param  array<string, mixed>  $overrides
 */
function complianceCompanyOf(?User $user = null, array $overrides = []): Company
{
    return app(CreateCompany::class)->handle($user ?? complianceUser(), companyPayload($overrides));
}

/*
|--------------------------------------------------------------------------
| Signalements et modération (module Moderation)
|--------------------------------------------------------------------------
*/

/**
 * Masque un contenu par son adaptateur (sans décision ni email du module Moderation) et
 * renvoie l'uuid de la décision fictive qui l'a masqué.
 */
function restrictContent(string $type, string $ref): string
{
    $decision = (string) Str::uuid();
    ModerationTargets::get($type)->restrict($ref, $decision);

    return $decision;
}

/**
 * Masque un contenu par son adaptateur au nom d'une décision existante.
 */
function restrictContentWith(string $type, string $ref, string $decisionUuid): void
{
    ModerationTargets::get($type)->restrict($ref, $decisionUuid);
}

/*
|--------------------------------------------------------------------------
| Marketplace (offres, devis, comparateur)
|--------------------------------------------------------------------------
*/

/**
 * Identité professionnelle valide (micro-entreprise fictive, SIREN de forme valide).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sellerProfilePayload(array $overrides = []): array
{
    return [
        'legal_name' => 'Camille Exemple',
        'trade_name' => 'Atelier Exemple',
        'legal_form' => 'ei_micro',
        'registration_number' => '123 456 782',
        'registry' => 'rne',
        'vat_number' => 'FR 11 123456782',
        'address_line1' => '1 rue de l\'Exemple',
        'postal_code' => '75001',
        'city' => 'Paris',
        'country' => 'FR',
        'phone' => '+33 1 23 45 67 89',
        'email' => 'contact@atelier-exemple.test',
        'insurer_name' => 'Assurance Exemple',
        'insurer_contact' => '10 avenue de l\'Assureur, 69001 Lyon',
        'insurance_coverage' => 'France métropolitaine',
        'mediator_name' => 'Médiateur Exemple',
        'mediator_url' => 'https://www.mediateur-exemple.fr',
        'self_certification' => true,
        'self_certification_version' => (string) config('marketplace.self_certification.version'),
        ...$overrides,
    ];
}

/**
 * Prestataire au profil publié, avec une identité professionnelle complète.
 *
 * @param  array<string, mixed>  $sellerAttributes
 */
function marketplaceProvider(array $sellerAttributes = []): User
{
    $provider = browsingProvider();
    SellerProfile::factory()->create(['user_id' => $provider->id, ...$sellerAttributes]);

    return $provider;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function offerPayload(array $overrides = []): array
{
    $category = ServiceCategory::query()->where('slug', 'sites-web')->first() ?? serviceCategory('sites-web', 'Sites web');

    return [
        'title' => 'Création de site vitrine',
        'summary' => 'Un site vitrine responsive de cinq pages, livré en trois semaines avec sa mise en ligne.',
        'description' => 'Maquette, intégration, formulaire de contact, référencement de base et formation à la mise à jour.',
        'category' => $category->slug,
        'pricing_mode' => 'from',
        'price_cents' => 150000,
        'delivery_mode' => 'remote',
        'country' => 'FR',
        'city' => 'Lyon',
        ...$overrides,
    ];
}

/**
 * Offre publiée d'un prestataire (créée par la factory, sans passer par l'API).
 *
 * @param  array<string, mixed>  $attributes
 */
function publishedOfferOf(User $provider, array $attributes = []): Offer
{
    return Offer::factory()->published()->create(['user_id' => $provider->id, ...$attributes]);
}

/**
 * Demande de mise en relation acceptée entre un porteur de projet et un prestataire doté d'une
 * identité professionnelle : [client, prestataire, demande].
 *
 * @param  array<string, mixed>  $sellerAttributes
 * @return array{0: User, 1: User, 2: MatchRequest}
 */
function acceptedQuoteRequest(string $clientRole = 'particulier', array $sellerAttributes = [], ?User $client = null): array
{
    $client ??= projectOwner($clientRole);
    $provider = marketplaceProvider($sellerAttributes);
    $matchRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf($client)));

    return [$client, $provider, $matchRequest];
}

/**
 * Brouillon de devis valide (deux lignes au taux de test fr.vat.rate.standard).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quotePayload(?string $matchRequestUuid = null, array $overrides = []): array
{
    return [
        ...($matchRequestUuid === null ? [] : ['match_request' => $matchRequestUuid]),
        'title' => 'Création d\'un site vitrine',
        'scope' => 'Conception et réalisation d\'un site vitrine de cinq pages, avec mise en ligne et formation.',
        'client' => [
            'name' => 'Alex Martin',
            'address' => '5 place du Marché, 69002 Lyon',
            'execution_place' => 'À distance',
        ],
        'validity_days' => 30,
        'start_date' => '2027-04-01',
        'duration' => 'Trois semaines',
        'payment_terms' => 'Paiement à réception de facture, sous 30 jours.',
        'lines' => [
            ['line_type' => 'labour', 'designation' => 'Conception et intégration', 'quantity_milli' => 3500, 'unit' => 'day', 'unit_price_cents' => 45000, 'vat_rate_code' => 'fr.vat.rate.standard'],
            ['line_type' => 'service', 'designation' => 'Mise en ligne et formation', 'quantity_milli' => 1000, 'unit' => 'lump_sum', 'unit_price_cents' => 30000, 'vat_rate_code' => 'fr.vat.rate.standard'],
        ],
        ...$overrides,
    ];
}

/**
 * Devis envoyé par l'API : [client, prestataire, devis].
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: User, 1: User, 2: Quote}
 */
function sentQuote(string $clientRole = 'particulier', array $overrides = [], ?User $client = null): array
{
    if (! ComplianceRule::query()->where('code', 'fr.vat.rate.standard')->exists()) {
        verifiedVatRate('fr.vat.rate.standard', 2000);
    }

    [$client, $provider, $matchRequest] = acceptedQuoteRequest($clientRole, client: $client);
    $uuid = test()->actingAs($provider)->postJson('/api/v1/me/quotes', quotePayload($matchRequest->uuid, $overrides))->assertCreated()->json('data.uuid');
    test()->actingAs($provider)->postJson("/api/v1/me/quotes/{$uuid}/send")->assertOk();

    return [$client, $provider, Quote::query()->where('uuid', $uuid)->sole()];
}

/*
|--------------------------------------------------------------------------
| Rendez-vous (module Appointments)
|--------------------------------------------------------------------------
*/

/**
 * Prestataire au profil publié dont le calendrier est ouvert aux réservations (Europe/Paris,
 * créneaux de 30 minutes, sans marge ni délai de prévenance, horizon de 30 jours).
 *
 * @param  array<string, mixed>  $calendar
 */
function appointmentProvider(array $calendar = []): User
{
    $provider = browsingProvider();
    Calendar::factory()->create(['user_id' => $provider->id, ...$calendar]);

    return $provider;
}

function appointmentCalendarOf(User $provider): Calendar
{
    return Calendar::query()->where('user_id', $provider->id)->sole();
}

/**
 * Type de rendez-vous d'un prestataire (premier échange de 30 minutes en visio par défaut).
 *
 * @param  array<string, mixed>  $attributes
 */
function appointmentTypeOf(User $provider, array $attributes = []): AppointmentType
{
    return AppointmentType::factory()->create(['calendar_id' => appointmentCalendarOf($provider)->id, ...$attributes]);
}

/**
 * Plages hebdomadaires du prestataire, en heure locale : [jour ISO, début, fin] en minutes.
 * Par défaut, tous les jours de 09:00 à 12:00.
 *
 * @param  list<array{0: int, 1: int, 2: int}>|null  $ranges
 */
function weeklyAvailability(User $provider, ?array $ranges = null): void
{
    $calendar = appointmentCalendarOf($provider);
    $ranges ??= array_map(fn (int $day): array => [$day, 9 * 60, 12 * 60], range(1, 7));

    foreach ($ranges as [$weekday, $start, $end]) {
        (new AvailabilityRule)->forceFill(['calendar_id' => $calendar->id, 'weekday' => $weekday, 'start_minute' => $start, 'end_minute' => $end])->save();
    }
}

/**
 * Demande de mise en relation acceptée entre un porteur de projet et un prestataire dont le
 * calendrier est ouvert : [client, prestataire, demande].
 *
 * @param  array<string, mixed>  $calendar
 * @return array{0: User, 1: User, 2: MatchRequest}
 */
function appointmentRelation(string $clientRole = 'particulier', array $calendar = [], ?User $client = null): array
{
    $client ??= projectOwner($clientRole);
    $provider = appointmentProvider($calendar);
    $matchRequest = app(AcceptMatchRequest::class)->handle(pendingProposal($provider, publishedProjectOf($client)));

    return [$client, $provider, $matchRequest];
}

/*
|--------------------------------------------------------------------------
| Workspaces et copilote (module Workspaces, lot 5)
|--------------------------------------------------------------------------
*/

/**
 * Particulier ou entrepreneur qui utilise les workspaces (email vérifié par défaut).
 */
function workspaceOwner(string $role = 'particulier', bool $verified = true): User
{
    return projectOwner($role, $verified);
}

/**
 * Workspace actif d'un utilisateur (site vitrine en France par défaut).
 *
 * @param  array<string, mixed>  $attributes
 */
function workspaceOf(User $user, array $attributes = []): Workspace
{
    return Workspace::factory()->for($user)->create($attributes);
}
