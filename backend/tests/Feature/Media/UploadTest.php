<?php

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Storage::fake('public');
});

function uploadMedia(User $user, ?UploadedFile $file, ?string $purpose = 'avatar'): TestResponse
{
    return test()->actingAs($user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/media', array_filter(['file' => $file, 'purpose' => $purpose], fn ($value) => $value !== null));
}

function storedContents(Media $media): string
{
    return (string) Storage::disk('public')->get($media->path);
}

describe('upload valide', function (): void {
    it('réencode chaque format accepté en WebP avec un nom généré', function (string $format): void {
        $user = User::factory()->create();

        $response = uploadMedia($user, testImage($format, 300, 200, 'mon-fichier.'.$format))
            ->assertCreated()
            ->assertJsonStructure(['data' => ['uuid', 'purpose', 'url', 'width', 'height']])
            ->assertJsonPath('data.purpose', 'avatar')
            ->assertJsonPath('data.width', 300)
            ->assertJsonPath('data.height', 200);

        $media = Media::query()->sole();
        $contents = storedContents($media);

        expect($response->json('data.uuid'))->toBe($media->uuid)
            ->and($media->user_id)->toBe($user->id)
            ->and($media->mime_type)->toBe('image/webp')
            ->and($media->attached_at)->toBeNull()
            ->and($media->size)->toBe(strlen($contents))
            ->and($media->path)->toBe('avatar/'.now()->format('Y/m').'/'.$media->uuid.'.webp')
            ->and($media->path)->not->toContain('mon-fichier')
            ->and(substr($contents, 0, 4))->toBe('RIFF')
            ->and(substr($contents, 8, 4))->toBe('WEBP')
            ->and($response->json('data.url'))->toBe(Storage::disk('public')->url($media->path));
    })->with(['jpeg', 'png', 'webp']);

    it('supprime les métadonnées EXIF (GPS compris) et le contenu caché', function (): void {
        $file = jpegWithMetadata('SECRET-EXIF-MARKER', 'SECRET-HIDDEN-PAYLOAD');

        // Le fichier source contient bien une position GPS lisible.
        $exif = exif_read_data((string) $file->getRealPath(), 'GPS');
        expect($exif)->toBeArray()->toHaveKey('GPSLatitude');

        uploadMedia(User::factory()->create(), $file)->assertCreated();

        $contents = storedContents(Media::query()->sole());

        expect($contents)
            ->not->toContain('SECRET-EXIF-MARKER')
            ->not->toContain('SECRET-HIDDEN-PAYLOAD')
            ->not->toContain('Exif')
            ->not->toContain('EXIF');
    });

    it('réduit l\'image aux dimensions maximales de l\'usage sans la déformer', function (): void {
        uploadMedia(User::factory()->create(), testImage('png', 2000, 1000))
            ->assertCreated()
            ->assertJsonPath('data.width', 512)
            ->assertJsonPath('data.height', 256);

        $size = getimagesizefromstring(storedContents(Media::query()->sole()));
        expect([$size[0], $size[1]])->toBe([512, 256]);
    });

    it('n\'agrandit pas une petite image', function (): void {
        uploadMedia(User::factory()->create(), testImage('jpeg', 150, 140))
            ->assertCreated()
            ->assertJsonPath('data.width', 150)
            ->assertJsonPath('data.height', 140);
    });

    it('accepte un logo d\'un prestataire, avec ses propres dimensions maximales', function (): void {
        $user = User::factory()->create();
        $user->assignRole('prestataire');

        uploadMedia($user, testImage('png', 2000, 2000), 'provider_logo')
            ->assertCreated()
            ->assertJsonPath('data.purpose', 'provider_logo')
            ->assertJsonPath('data.width', 1024);

        expect(Media::query()->sole()->path)->toStartWith('provider_logo/');
    });

    it('n\'expose ni l\'id interne, ni le propriétaire, ni le disque, ni le chemin', function (): void {
        $response = uploadMedia(User::factory()->create(), testImage('jpeg'))->assertCreated();
        $media = Media::query()->sole();

        expect(array_keys($response->json('data')))->toEqualCanonicalizing(['uuid', 'purpose', 'url', 'width', 'height'])
            ->and($response->getContent())->not->toContain(Storage::disk('public')->path($media->path))
            ->and($response->getContent())->not->toContain('"disk"')
            ->and($response->getContent())->not->toContain('"path"');
    });
});

describe('refus', function (): void {
    it('refuse les formats non acceptés et les faux fichiers image', function (UploadedFile $file): void {
        uploadMedia(User::factory()->create(), $file)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        expect(Media::query()->count())->toBe(0);
        expect(Storage::disk('public')->allFiles())->toBe([]);
    })->with([
        'SVG' => fn () => uploadedFileWithContents(
            '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="500"><script>alert(1)</script></svg>',
            'logo.svg',
        ),
        'SVG renommé en .png' => fn () => uploadedFileWithContents(
            '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="500"></svg>',
            'logo.png',
        ),
        'GIF' => fn () => testImage('gif'),
        'GIF renommé en .jpg' => fn () => testImage('gif', clientName: 'photo.jpg'),
        'texte renommé en .jpg' => fn () => uploadedFileWithContents(str_repeat('Ceci n\'est pas une image. ', 100), 'photo.jpg'),
        'PHP renommé en .jpg' => fn () => uploadedFileWithContents('<?php echo "hello"; ?>', 'photo.jpg'),
    ]);

    it('refuse un JPEG tronqué qui ne peut pas être décodé', function (): void {
        $contents = (string) file_get_contents((string) testImage('jpeg', 400, 400)->getRealPath());

        uploadMedia(User::factory()->create(), uploadedFileWithContents(substr($contents, 0, 400), 'photo.jpg'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        expect(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('refuse un fichier trop lourd', function (): void {
        // PNG de bruit aléatoire, peu compressible : plus de 5 Mo.
        $image = imagecreatetruecolor(1400, 1400);
        for ($y = 0; $y < 1400; $y++) {
            for ($x = 0; $x < 1400; $x++) {
                imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($image, null, 0);
        $file = uploadedFileWithContents((string) ob_get_clean(), 'lourd.png');

        expect($file->getSize())->toBeGreaterThan(5 * 1024 * 1024);

        uploadMedia(User::factory()->create(), $file)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    });

    it('refuse une image trop petite', function (): void {
        uploadMedia(User::factory()->create(), testImage('png', 100, 300))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    });

    it('refuse une image au-delà du plafond de pixels, avant de la décoder', function (): void {
        config(['media.max_pixels' => 1_000_000]);

        uploadMedia(User::factory()->create(), testImage('png', 1100, 1000))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    });

    it('refuse un purpose inconnu ou absent', function (?string $purpose): void {
        uploadMedia(User::factory()->create(), testImage('jpeg'), $purpose)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['purpose']);
    })->with(['inconnu' => 'banner', 'absent' => null]);

    it('refuse un usage pas encore disponible', function (string $purpose): void {
        $user = User::factory()->create();
        $user->assignRole('admin');

        uploadMedia($user, testImage('jpeg', 1600, 900), $purpose)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['purpose' => 'pas encore disponible']);
    })->with(['event_cover', 'article_cover']);

    it('refuse un fichier absent', function (): void {
        uploadMedia(User::factory()->create(), null)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    });

    it('réserve provider_logo au rôle prestataire', function (string $role): void {
        $user = User::factory()->create();
        $user->assignRole($role);

        uploadMedia($user, testImage('png'), 'provider_logo')->assertForbidden();

        expect(Media::query()->count())->toBe(0);
    })->with(['particulier', 'entrepreneur', 'redacteur', 'admin']);

    it('refuse un utilisateur dont l\'email n\'est pas vérifié', function (): void {
        uploadMedia(User::factory()->unverified()->create(), testImage('jpeg'))->assertForbidden();

        expect(Media::query()->count())->toBe(0);
    });

    it('renvoie 401 sans authentification', function (): void {
        $this->withHeader('Accept', 'application/json')
            ->post('/api/v1/media', ['file' => testImage('jpeg'), 'purpose' => 'avatar'])
            ->assertUnauthorized();
    });
});

describe('limites', function (): void {
    it('limite les uploads à 20 par minute et par utilisateur', function (): void {
        $user = User::factory()->create();

        foreach (range(1, 20) as $attempt) {
            uploadMedia($user, null)->assertUnprocessable();
        }

        uploadMedia($user, testImage('jpeg'))->assertTooManyRequests();

        // La limite est propre à chaque utilisateur.
        uploadMedia(User::factory()->create(), testImage('jpeg'))->assertCreated();
    });

    it('refuse l\'upload au-delà de 20 médias non rattachés', function (): void {
        $user = User::factory()->create();
        Media::factory()->count(20)->for($user)->create();
        Media::factory()->count(3)->for($user)->attached()->create();

        uploadMedia($user, testImage('jpeg'))
            ->assertTooManyRequests()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'pas encore utilisées'));

        expect(Media::query()->where('user_id', $user->id)->count())->toBe(23);
    });

    it('accepte l\'upload tant que le plafond de médias non rattachés n\'est pas atteint', function (): void {
        $user = User::factory()->create();
        Media::factory()->count(19)->for($user)->create();
        Media::factory()->count(5)->create();

        uploadMedia($user, testImage('jpeg'))->assertCreated();
    });
});
