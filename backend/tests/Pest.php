<?php

use App\Models\ServiceCategory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
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
