<?php

use App\Models\Media;
use App\Models\User;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

function userWithAvatar(): User
{
    $user = User::factory()->create();
    $avatar = Media::factory()->for($user)->attached()->withFile()->create();
    $user->forceFill(['avatar_media_id' => $avatar->id])->save();

    return $user;
}

it('rattache un avatar envoyé par l\'utilisateur', function (): void {
    $user = User::factory()->create();

    $uuid = $this->actingAs($user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/media', ['file' => testImage('jpeg'), 'purpose' => 'avatar'])
        ->assertCreated()
        ->json('data.uuid');

    $media = Media::query()->where('uuid', $uuid)->sole();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', ['avatar' => $uuid])
        ->assertOk()
        ->assertJsonPath('data.avatar_url', $media->url());

    expect($media->fresh()->attached_at)->not->toBeNull()
        ->and($user->fresh()->avatar_media_id)->toBe($media->id);

    $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.avatar_url', $media->url());
});

it('remplace l\'avatar et supprime l\'ancien média avec son fichier', function (): void {
    $user = userWithAvatar();
    $old = $user->avatar;
    $new = Media::factory()->for($user)->withFile()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', ['avatar' => $new->uuid])
        ->assertOk()
        ->assertJsonPath('data.avatar_url', $new->url());

    $this->assertModelMissing($old);
    Storage::disk('public')->assertMissing($old->path);
    Storage::disk('public')->assertExists($new->path);
    expect($new->fresh()->isAttached())->toBeTrue();
});

it('retire l\'avatar avec null et supprime son fichier', function (): void {
    $user = userWithAvatar();
    $avatar = $user->avatar;

    $this->actingAs($user)
        ->patchJson('/api/v1/me', ['avatar' => null])
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    $this->assertModelMissing($avatar);
    Storage::disk('public')->assertMissing($avatar->path);
    expect($user->fresh()->avatar_media_id)->toBeNull();
});

it('ne change rien si l\'avatar actuel est renvoyé ou si le champ est absent', function (Closure $payload): void {
    $user = userWithAvatar();
    $avatar = $user->avatar;

    $this->actingAs($user)
        ->patchJson('/api/v1/me', $payload($avatar))
        ->assertOk()
        ->assertJsonPath('data.avatar_url', $avatar->url());

    $this->assertModelExists($avatar);
    Storage::disk('public')->assertExists($avatar->path);
})->with([
    'même uuid' => fn (Media $avatar): array => ['avatar' => $avatar->uuid],
    'champ absent' => fn (Media $avatar): array => ['name' => 'Nouveau nom'],
]);

it('refuse un média qui ne peut pas devenir l\'avatar', function (Closure $makeMedia): void {
    $user = userWithAvatar();
    $current = $user->avatar;
    $media = $makeMedia($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/me', ['avatar' => $media instanceof Media ? $media->uuid : $media])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['avatar']);

    expect($user->fresh()->avatar_media_id)->toBe($current->id);
    Storage::disk('public')->assertExists($current->path);

    if ($media instanceof Media) {
        expect($media->fresh()->isAttached())->toBe($media->isAttached());
    }
})->with([
    'média d\'un autre utilisateur' => fn (User $user) => Media::factory()->create(),
    'mauvais purpose' => fn (User $user) => Media::factory()->for($user)->purpose(MediaPurpose::ProviderLogo)->create(),
    'média déjà rattaché ailleurs' => fn (User $user) => Media::factory()->for($user)->attached()->create(),
    'uuid inexistant' => fn (User $user) => '0b8f9d0e-1f7a-4c1e-9a43-7d2a4b5c6e7f',
    'pas un uuid' => fn (User $user) => '1',
]);

it('n\'expose ni l\'identifiant ni le chemin de l\'avatar', function (): void {
    $user = userWithAvatar();

    $content = (string) $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->getContent();

    expect($content)
        ->not->toContain('avatar_media_id')
        ->not->toContain('"path"')
        ->not->toContain('"disk"');
});
