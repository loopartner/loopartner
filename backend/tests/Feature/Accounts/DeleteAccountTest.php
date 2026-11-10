<?php

use App\Models\Media;
use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Support\Media\MediaPurpose;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('refuse la suppression avec un mauvais mot de passe', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/me', ['password' => 'mauvais-mot-de-passe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    $this->assertModelExists($user);
    $this->assertAuthenticatedAs($user);
});

it('refuse la suppression sans mot de passe', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/me')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    $this->assertModelExists($user);
});

it('supprime le compte, ses rôles et ses sessions, puis déconnecte l\'utilisateur', function (): void {
    // Driver de production : les sessions sont stockées en base.
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    $user->assignRole('particulier');
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        ['id' => 'session-a', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'session-b', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
    ]);

    $this->actingAs($user)
        ->withHeader('Referer', config('app.frontend_url'))
        ->deleteJson('/api/v1/me', ['password' => 'password'])
        ->assertNoContent();

    $this->assertModelMissing($user);
    $this->assertGuest('web');
    $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id, 'model_type' => User::class]);
    $this->assertDatabaseMissing('sessions', ['id' => 'session-a']);
    $this->assertDatabaseHas('sessions', ['id' => 'session-b']);

    // Nouvelle requête : les gardes ne conservent pas l'utilisateur d'une requête à l'autre.
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('renvoie 401 sans authentification', function (): void {
    $this->deleteJson('/api/v1/me', ['password' => 'password'])->assertUnauthorized();
});

it('limite les tentatives de suppression à 6 par minute', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 6) as $attempt) {
        $this->actingAs($user)
            ->deleteJson('/api/v1/me', ['password' => 'mauvais-mot-de-passe'])
            ->assertUnprocessable();
    }

    $this->actingAs($user)
        ->deleteJson('/api/v1/me', ['password' => 'password'])
        ->assertTooManyRequests();

    $this->assertModelExists($user);
});

it('supprime tous les médias du compte et leurs fichiers', function (): void {
    Storage::fake('public');

    $user = User::factory()->create();
    $user->assignRole('prestataire');

    $avatar = Media::factory()->for($user)->attached()->withFile()->create();
    $user->forceFill(['avatar_media_id' => $avatar->id])->save();

    $logo = Media::factory()->for($user)->purpose(MediaPurpose::ProviderLogo)->attached()->withFile()->create();
    $profile = ProviderProfile::factory()->for($user)->create();
    $profile->forceFill(['logo_media_id' => $logo->id])->save();

    $pending = Media::factory()->for($user)->withFile()->create();
    $otherUsersMedia = Media::factory()->withFile()->create();

    $this->actingAs($user)
        ->withHeader('Referer', config('app.frontend_url'))
        ->deleteJson('/api/v1/me', ['password' => 'password'])
        ->assertNoContent();

    $this->assertModelMissing($user);
    $this->assertModelMissing($profile);

    foreach ([$avatar, $logo, $pending] as $media) {
        $this->assertModelMissing($media);
        Storage::disk('public')->assertMissing($media->path);
    }

    $this->assertModelExists($otherUsersMedia);
    Storage::disk('public')->assertExists($otherUsersMedia->path);
});

it('empêche en base la suppression d\'un utilisateur qui possède encore des médias', function (): void {
    $user = User::factory()->create();
    Media::factory()->for($user)->create();

    expect(fn () => $user->delete())->toThrow(QueryException::class);
});
