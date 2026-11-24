<?php

use App\Models\Media;
use App\Models\User;
use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Facades\Storage;

/*
| Décision 82 : un profil prestataire ne se publie qu'avec une photo (logo), une bannière, une
| description d'au moins accounts.provider_profile.min_bio_length caractères et une catégorie.
| phpunit.xml désactive la règle pour le reste de la suite : elle est réactivée ici.
*/

beforeEach(function (): void {
    Storage::fake('public');
    config(['accounts.provider_profile.require_complete_profile' => true]);
    serviceCategory('design');
});

function completenessProvider(): User
{
    $user = User::factory()->create();
    $user->assignRole('prestataire');

    return $user;
}

function completenessMedia(User $user, MediaPurpose $purpose): Media
{
    return Media::factory()->for($user)->purpose($purpose)->withFile()->create();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function completeProfilePayload(User $user, array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Atelier Complet',
        'bio' => str_repeat('Conseil et accompagnement des créateurs d’entreprise. ', 3),
        'categories' => ['design'],
        'logo' => completenessMedia($user, MediaPurpose::ProviderLogo)->uuid,
        'banner' => completenessMedia($user, MediaPurpose::ProviderBanner)->uuid,
        'status' => 'published',
    ], $overrides);
}

it('publie un profil complet et expose sa bannière', function (): void {
    $user = completenessProvider();
    $payload = completeProfilePayload($user);
    $banner = Media::query()->where('uuid', $payload['banner'])->sole();

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.banner_url', $banner->url());

    $slug = ProviderProfile::query()->where('user_id', $user->id)->value('slug');
    $this->getJson("/api/v1/providers/{$slug}")->assertOk()->assertJsonPath('data.banner_url', $banner->url());
    expect($banner->fresh()->isAttached())->toBeTrue();
});

it('refuse la publication sans photo, sans bannière ou sans description suffisante (422)', function (string $field, mixed $value): void {
    $user = completenessProvider();

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', completeProfilePayload($user, [$field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(ProviderProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with([
    'sans photo' => ['logo', null],
    'sans bannière' => ['banner', null],
    'description trop courte' => ['bio', 'Trop court.'],
    'sans description' => ['bio', null],
]);

it('garde un brouillon incomplet enregistrable', function (): void {
    $user = completenessProvider();

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', ['display_name' => 'Brouillon', 'status' => 'draft'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.banner_url', null);
});

it('reprend les médias déjà rattachés quand la requête ne les renvoie pas', function (): void {
    $user = completenessProvider();
    $this->actingAs($user)->putJson('/api/v1/me/provider-profile', completeProfilePayload($user))->assertCreated();

    $payload = completeProfilePayload($user);
    unset($payload['logo'], $payload['banner']);

    $this->actingAs($user)->putJson('/api/v1/me/provider-profile', $payload)->assertOk()->assertJsonPath('data.status', 'published');
});

it('refuse de retirer la bannière d’un profil publié, mais l’accepte en brouillon', function (): void {
    $user = completenessProvider();
    $this->actingAs($user)->putJson('/api/v1/me/provider-profile', completeProfilePayload($user))->assertCreated();
    $payload = completeProfilePayload($user);
    unset($payload['logo']);

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', [...$payload, 'banner' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['banner']);

    $this->actingAs($user)
        ->putJson('/api/v1/me/provider-profile', [...$payload, 'banner' => null, 'status' => 'draft'])
        ->assertOk()
        ->assertJsonPath('data.banner_url', null);

    expect(ProviderProfile::query()->where('user_id', $user->id)->sole()->status)->toBe(ProviderProfileStatus::Draft);
});

it('remplace la bannière et supprime l’ancienne avec son fichier', function (): void {
    $user = completenessProvider();
    $first = completeProfilePayload($user);
    $this->actingAs($user)->putJson('/api/v1/me/provider-profile', $first)->assertCreated();
    $old = Media::query()->where('uuid', $first['banner'])->sole();
    $new = completenessMedia($user, MediaPurpose::ProviderBanner);

    $payload = completeProfilePayload($user, ['banner' => $new->uuid]);
    unset($payload['logo']);

    $this->actingAs($user)->putJson('/api/v1/me/provider-profile', $payload)->assertOk()->assertJsonPath('data.banner_url', $new->url());

    $this->assertModelMissing($old);
    Storage::disk('public')->assertMissing($old->path);
});

it('refuse la bannière d’un autre utilisateur ou un média d’un autre usage (422)', function (): void {
    $user = completenessProvider();
    $other = completenessProvider();

    foreach ([completenessMedia($other, MediaPurpose::ProviderBanner), completenessMedia($user, MediaPurpose::ProviderLogo)] as $media) {
        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', completeProfilePayload($user, ['banner' => $media->uuid]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['banner']);
    }
});

it('réserve l’envoi d’une bannière aux prestataires (403)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('particulier');

    $this->actingAs($user)
        ->post('/api/v1/media', ['file' => testImage('png', 1600, 400), 'purpose' => 'provider_banner'], ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('n’expose ni le disque ni l’identifiant interne de la bannière', function (): void {
    $user = completenessProvider();
    $response = $this->actingAs($user)->putJson('/api/v1/me/provider-profile', completeProfilePayload($user))->assertCreated();
    expect($response->getContent())
        ->not->toContain('banner_media_id')
        ->not->toContain('"disk"')
        ->not->toContain('"path"');
});
