<?php

use App\Modules\Accounts\Actions\FindPublishedProvider;
use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;

/*
| Actions publiques du module Accounts utilisées par d'autres modules : elles renvoient des
| tableaux simples, jamais le modèle ProviderProfile, et seulement ce que montre la page publique.
*/

it('trouve un prestataire publié par son slug, avec son résumé public', function (): void {
    $provider = browsingProvider();
    $profile = ProviderProfile::query()->where('user_id', $provider->id)->sole();

    $found = app(FindPublishedProvider::class)->handle($profile->slug);

    expect($found)->toBe([
        'user_id' => $provider->id,
        'summary' => [
            'display_name' => $profile->display_name,
            'slug' => $profile->slug,
            'headline' => $profile->headline,
            'city' => $profile->city,
            'country' => $profile->country,
            'logo_url' => null,
        ],
    ]);
});

it('ne trouve ni un profil en brouillon ni un slug inconnu', function (): void {
    $profile = ProviderProfile::factory()->create(['status' => ProviderProfileStatus::Draft]);

    expect(app(FindPublishedProvider::class)->handle($profile->slug))->toBeNull()
        ->and(app(FindPublishedProvider::class)->handle('inconnu'))->toBeNull();
});

it('résume plusieurs prestataires en une requête, sans slug pour un profil dépublié', function (): void {
    $published = browsingProvider();
    $draft = ProviderProfile::factory()->create(['status' => ProviderProfileStatus::Draft]);
    $withoutProfile = projectOwner('particulier');

    $summaries = app(GetProviderSummaries::class)->handle([$published->id, $draft->user_id, $withoutProfile->id, $published->id]);

    expect(array_keys($summaries))->toEqualCanonicalizing([$published->id, $draft->user_id])
        ->and($summaries[$draft->user_id]['slug'])->toBeNull()
        ->and($summaries[$draft->user_id]['display_name'])->toBe($draft->display_name)
        ->and($summaries[$published->id])->not->toHaveKeys(['email', 'name', 'user_id', 'id'])
        ->and(app(GetProviderSummaries::class)->handle([]))->toBe([]);
});
