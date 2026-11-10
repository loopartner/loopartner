<?php

use App\Models\Media;
use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

function logoProvider(): User
{
    $user = User::factory()->create();
    $user->assignRole('prestataire');

    return $user;
}

function logoMedia(User $user): Media
{
    return Media::factory()->for($user)->purpose(MediaPurpose::ProviderLogo)->withFile()->create();
}

function profileWithLogo(User $user): ProviderProfile
{
    $logo = logoMedia($user);
    $logo->forceFill(['attached_at' => now()])->save();

    $profile = ProviderProfile::factory()->for($user)->published()->create(['slug' => 'atelier-logo']);
    $profile->forceFill(['logo_media_id' => $logo->id])->save();

    return $profile;
}

function logoPayload(array $overrides = []): array
{
    return array_merge(['display_name' => 'Atelier Logo', 'status' => 'published'], $overrides);
}

it('rattache le logo à la création du profil et l\'expose sur la page publique', function (): void {
    $user = logoProvider();
    $logo = logoMedia($user);

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', logoPayload(['logo' => $logo->uuid]))
        ->assertCreated()
        ->assertJsonPath('data.logo_url', $logo->url());

    expect($logo->fresh()->isAttached())->toBeTrue();

    $this->actingAs($user)->getJson('/api/v1/me/provider-profile')->assertJsonPath('data.logo_url', $logo->url());
    $this->getJson('/api/v1/providers/atelier-logo')->assertOk()->assertJsonPath('data.logo_url', $logo->url());
});

it('renvoie logo_url à null sans logo', function (): void {
    $user = logoProvider();
    ProviderProfile::factory()->for($user)->published()->create(['slug' => 'sans-logo']);

    $this->actingAs($user)->getJson('/api/v1/me/provider-profile')->assertJsonPath('data.logo_url', null);
    $this->getJson('/api/v1/providers/sans-logo')->assertJsonPath('data.logo_url', null);
});

it('remplace le logo et supprime l\'ancien média avec son fichier', function (): void {
    $user = logoProvider();
    $old = profileWithLogo($user)->logo;
    $new = logoMedia($user);

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', logoPayload(['logo' => $new->uuid]))
        ->assertOk()
        ->assertJsonPath('data.logo_url', $new->url());

    $this->assertModelMissing($old);
    Storage::disk('public')->assertMissing($old->path);
    Storage::disk('public')->assertExists($new->path);
});

it('retire le logo avec null et supprime son fichier', function (): void {
    $user = logoProvider();
    $profile = profileWithLogo($user);
    $logo = $profile->logo;

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', logoPayload(['logo' => null]))
        ->assertOk()
        ->assertJsonPath('data.logo_url', null);

    $this->assertModelMissing($logo);
    Storage::disk('public')->assertMissing($logo->path);
    expect($profile->fresh()->logo_media_id)->toBeNull();
});

it('garde le logo quand le champ est absent du PUT', function (): void {
    $user = logoProvider();
    $logo = profileWithLogo($user)->logo;

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', logoPayload(['display_name' => 'Nouveau nom']))
        ->assertOk()
        ->assertJsonPath('data.logo_url', $logo->url());

    $this->assertModelExists($logo);
    Storage::disk('public')->assertExists($logo->path);
});

it('refuse un média qui ne peut pas devenir le logo', function (Closure $makeMedia): void {
    $user = logoProvider();
    $profile = profileWithLogo($user);
    $media = $makeMedia($user);

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', logoPayload(['logo' => $media->uuid]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);

    expect($profile->fresh()->logo_media_id)->toBe($profile->logo_media_id)
        ->and($media->fresh()->isAttached())->toBeFalse();
})->with([
    'média d\'un autre utilisateur' => fn (User $user) => logoMedia(logoProvider()),
    'mauvais purpose' => fn (User $user) => Media::factory()->for($user)->create(),
]);

it('n\'expose ni l\'identifiant ni le chemin du logo', function (): void {
    $user = logoProvider();
    profileWithLogo($user);

    foreach (['/api/v1/providers/atelier-logo', '/api/v1/me/provider-profile'] as $url) {
        expect((string) $this->actingAs($user)->getJson($url)->assertOk()->getContent())
            ->not->toContain('logo_media_id')
            ->not->toContain('"path"')
            ->not->toContain('"disk"');
    }
});
