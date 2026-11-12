<?php

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Accounts\Models\PremiumGrant;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleQuotaEntry;
use App\Modules\Blog\Support\Authors;
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
use App\Notifications\VerifyEmailNotification;
use App\Support\TwoFactorSession;
use Database\Factories\Modules\Blog\ArticleFactory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
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
    ], $overrides);
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
