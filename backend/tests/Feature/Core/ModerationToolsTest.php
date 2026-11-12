<?php

use App\Http\Middleware\LimitRequestBody;
use App\Models\Media;
use App\Models\User;
use App\Support\Links\LinkInspection;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use App\Support\Moderation\VersionGuard;
use App\Support\RateLimit\ActionThrottle;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/*
| Briques du noyau partagées par les modules (Events, Blog) : contrôle de version, inspection
| des liens, limite de débit dans une Action, taille du corps de requête, rattachement de
| plusieurs médias.
*/

/**
 * Média non rattaché, sans fichier (suffisant pour les règles de rattachement).
 */
function bareMedia(User $owner, MediaPurpose $purpose = MediaPurpose::ArticleImage): Media
{
    $media = new Media;
    $media->forceFill([
        'uuid' => (string) Str::uuid(),
        'user_id' => $owner->id,
        'purpose' => $purpose,
        'disk' => 'public',
        'path' => 'probe/'.Str::uuid().'.webp',
        'mime_type' => 'image/webp',
        'size' => 10,
        'width' => 400,
        'height' => 300,
    ])->save();

    return $media;
}

it('refuse une décision prise sur une version périmée (409)', function (): void {
    VersionGuard::assertSame(3, 3);

    expect(fn () => VersionGuard::assertSame(2, 3))->toThrow(ConflictHttpException::class);
});

it('regroupe les liens par domaine avec les signaux d\'alerte', function (): void {
    $report = LinkInspection::inspect([
        ['field' => 'body', 'link' => 'https://exemple.fr/a', 'host' => 'exemple.fr'],
        ['field' => 'title', 'link' => 'exemple.fr', 'host' => 'exemple.fr'],
        ['field' => 'body', 'link' => 'https://xn--pple-43d.com', 'host' => 'xn--pple-43d.com'],
        ['field' => 'body', 'link' => 'pas-un-lien', 'host' => null],
    ], ['exemple.fr']);

    expect($report['links'])->toHaveCount(4)
        ->and($report['domains'])->toBe([
            ['host' => 'exemple.fr', 'fields' => ['body', 'title'], 'punycode' => false, 'previously_approved' => true],
            ['host' => 'xn--pple-43d.com', 'fields' => ['body'], 'punycode' => true, 'previously_approved' => false],
        ]);
});

it('limite une Action sur plusieurs fenêtres, par clé', function (): void {
    $windows = ['hour' => [2, 3600], 'day' => [5, 86400]];

    ActionThrottle::hit('probe', 1, $windows);
    ActionThrottle::hit('probe', 1, $windows);

    expect(fn () => ActionThrottle::hit('probe', 1, $windows))->toThrow(ThrottleRequestsException::class);

    ActionThrottle::hit('probe', 2, $windows);
});

it('refuse un corps de requête trop volumineux (413) avant la validation', function (): void {
    Route::middleware(['api', LimitRequestBody::class.':1024'])
        ->post('api/v1/body-probe', fn () => response()->json(['ok' => true]));

    $this->postJson('/api/v1/body-probe', ['text' => str_repeat('a', 500)])->assertOk();
    $this->postJson('/api/v1/body-probe', ['text' => str_repeat('a', 2000)])
        ->assertStatus(413)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'trop volumineux'));
});

describe('MediaLibrary::attachMany', function (): void {
    beforeEach(fn () => Storage::fake('public'));

    it('rattache les médias libres de l\'utilisateur et accepte ceux déjà rattachés au contenu', function (): void {
        $owner = User::factory()->create();
        $free = bareMedia($owner);
        $kept = bareMedia($owner);
        $kept->forceFill(['attached_at' => now()])->save();

        $ids = app(MediaLibrary::class)->attachMany($owner, MediaPurpose::ArticleImage, [$free->uuid, $kept->uuid], [$kept->id], 'body');

        expect($ids)->toBe([$free->uuid => $free->id, $kept->uuid => $kept->id])
            ->and($free->fresh()->isAttached())->toBeTrue();
    });

    it('refuse un média qui n\'est pas rattachable', function (string $case): void {
        $owner = User::factory()->create();

        $uuid = match ($case) {
            'autre utilisateur' => bareMedia(User::factory()->create())->uuid,
            'autre usage' => bareMedia($owner, MediaPurpose::Avatar)->uuid,
            'rattaché ailleurs' => tap(bareMedia($owner), fn (Media $media) => $media->forceFill(['attached_at' => now()])->save())->uuid,
            default => (string) Str::uuid(),
        };

        expect(fn () => app(MediaLibrary::class)->attachMany($owner, MediaPurpose::ArticleImage, [$uuid], [], 'body'))
            ->toThrow(ValidationException::class);
    })->with(['autre utilisateur', 'autre usage', 'rattaché ailleurs', 'uuid inconnu']);
});
