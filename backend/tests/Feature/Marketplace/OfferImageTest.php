<?php

use App\Models\Media;
use App\Models\User;
use App\Modules\Marketplace\Models\Offer;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
| Décision 83 : une image de couverture facultative par offre (média offer_image du
| prestataire), affichée sur la liste et la page publique ; supprimée avec l'offre.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
    Storage::fake('public');
});

function offerImageOf(User $user, MediaPurpose $purpose = MediaPurpose::OfferImage): Media
{
    return Media::factory()->for($user)->purpose($purpose)->withFile()->create();
}

it('rattache l’image à la création et l’expose au prestataire et au public', function (): void {
    $provider = marketplaceProvider();
    $image = offerImageOf($provider);

    $uuid = $this->actingAs($provider)
        ->postJson('/api/v1/me/offers', offerPayload(['image' => $image->uuid]))
        ->assertCreated()
        ->assertJsonPath('data.image_url', $image->url())
        ->json('data.uuid');

    expect($image->fresh()->isAttached())->toBeTrue();

    $this->actingAs($provider)->postJson("/api/v1/me/offers/{$uuid}/publish")->assertOk();
    $slug = Offer::query()->where('uuid', $uuid)->value('slug');

    $this->getJson("/api/v1/offers/{$slug}")->assertOk()->assertJsonPath('data.image_url', $image->url());
    $this->getJson('/api/v1/offers')->assertOk()->assertJsonPath('data.0.image_url', $image->url());
});

it('renvoie image_url à null sans image', function (): void {
    $provider = marketplaceProvider();

    $this->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload())->assertCreated()->assertJsonPath('data.image_url', null);
});

it('remplace puis retire l’image, en supprimant chaque fois l’ancien fichier', function (): void {
    $provider = marketplaceProvider();
    $old = offerImageOf($provider);
    $uuid = $this->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload(['image' => $old->uuid]))->assertCreated()->json('data.uuid');
    $new = offerImageOf($provider);

    $this->actingAs($provider)->patchJson("/api/v1/me/offers/{$uuid}", ['image' => $new->uuid])->assertOk()->assertJsonPath('data.image_url', $new->url());
    $this->assertModelMissing($old);
    Storage::disk('public')->assertMissing($old->path);

    $this->actingAs($provider)->patchJson("/api/v1/me/offers/{$uuid}", ['image' => null])->assertOk()->assertJsonPath('data.image_url', null);
    $this->assertModelMissing($new);
});

it('garde l’image quand une modification ne l’envoie pas', function (): void {
    $provider = marketplaceProvider();
    $image = offerImageOf($provider);
    $uuid = $this->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload(['image' => $image->uuid]))->assertCreated()->json('data.uuid');

    $this->actingAs($provider)->patchJson("/api/v1/me/offers/{$uuid}", ['title' => 'Création de site vitrine sur mesure'])->assertOk()->assertJsonPath('data.image_url', $image->url());
});

it('supprime l’image et son fichier avec l’offre', function (): void {
    $provider = marketplaceProvider();
    $image = offerImageOf($provider);
    $uuid = $this->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload(['image' => $image->uuid]))->assertCreated()->json('data.uuid');

    $this->actingAs($provider)->deleteJson("/api/v1/me/offers/{$uuid}")->assertSuccessful();

    $this->assertModelMissing($image);
    Storage::disk('public')->assertMissing($image->path);
});

it('refuse l’image d’un autre prestataire ou d’un autre usage (422)', function (): void {
    $provider = marketplaceProvider();
    $other = marketplaceProvider();

    foreach ([offerImageOf($other), offerImageOf($provider, MediaPurpose::ProviderLogo), 'pas-un-uuid'] as $image) {
        $this->actingAs($provider)
            ->postJson('/api/v1/me/offers', offerPayload(['image' => $image instanceof Media ? $image->uuid : $image]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }
});

it('refuse d’assigner image_media_id directement (422)', function (): void {
    $provider = marketplaceProvider();
    $image = offerImageOf($provider);

    $this->actingAs($provider)
        ->postJson('/api/v1/me/offers', offerPayload(['image_media_id' => $image->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image_media_id']);
});

it('réserve l’envoi d’une image d’offre aux prestataires (403)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('entrepreneur');

    $this->actingAs($user)
        ->post('/api/v1/media', ['file' => testImage('png', 1000, 700), 'purpose' => 'offer_image'], ['Accept' => 'application/json'])
        ->assertForbidden();
});
