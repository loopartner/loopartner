<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use App\Modules\Accounts\Actions\RegisterAccount;
use Illuminate\Support\Facades\Notification;

/*
| Validation des champs texte saisis (pas de HTML) et masquage des mots de passe dans les traces
| d'exception (#[\SensitiveParameter]).
*/

it('refuse le HTML et les noms trop courts à l\'inscription', function (string $name): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', registrationPayload(['name' => $name]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(User::query()->count())->toBe(0);
})->with([
    'balise script' => '<script>alert(1)</script>',
    'balise image' => 'Camille <img src=x onerror=alert(1)>',
    'une seule lettre' => 'C',
]);

it('refuse le HTML et les noms trop courts dans PATCH /me', function (string $name): void {
    $user = User::factory()->create(['name' => 'Camille Martin']);

    $this->actingAs($user)->patchJson('/api/v1/me', ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($user->fresh()->name)->toBe('Camille Martin');
})->with([
    'balise script' => '<script>alert(1)</script>',
    'commentaire HTML' => 'Camille <!-- x -->',
    'une seule lettre' => 'C',
]);

it('accepte un nom avec un chevron isolé, des accents et une apostrophe', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/me', ['name' => "Zoé L'Hermitte <3"])->assertOk();
});

describe('caractères invisibles et de contrôle', function (): void {
    // Placés au milieu du texte : TrimStrings retire déjà certains de ces caractères aux extrémités.

    it('les refuse dans le nom, à l\'inscription et dans PATCH /me', function (): void {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Camille Martin']);

        $this->postJson('/api/v1/auth/register', registrationPayload(['name' => "Cam\u{202E}ille"]))
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->actingAs($user)->patchJson('/api/v1/me', ['name' => "Cam\u{200B}ille"])
            ->assertUnprocessable()->assertJsonValidationErrors(['name']);

        expect(User::query()->count())->toBe(1)
            ->and($user->fresh()->name)->toBe('Camille Martin');
    });

    it('les refuse dans chaque champ texte du profil prestataire', function (string $field): void {
        $provider = projectOwner('prestataire');
        $value = $field === 'website' ? "https://exem\u{202E}ple.com" : "Texte\u{2066} piégé";

        $this->actingAs($provider)->putJson('/api/v1/me/provider-profile', ['display_name' => 'Mon studio', $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);
    })->with(['display_name', 'headline', 'bio', 'city', 'website']);

    it('les refuse dans chaque champ texte d\'un projet, à la création et à la modification', function (string $field): void {
        serviceCategory('site-vitrine');
        $owner = projectOwner('particulier');
        $value = "Texte assez long\u{FEFF} pour passer la longueur minimale";

        $this->actingAs($owner)->postJson('/api/v1/me/projects', projectPayload([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/me/projects', projectPayload())->assertCreated()->json('data.uuid');
        $this->actingAs($owner)->patchJson("/api/v1/me/projects/{$uuid}", [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);
    })->with(['title', 'description', 'city']);
});

it('masque les mots de passe dans les traces d\'exception', function (string $class, string $method, int $position): void {
    $parameter = (new ReflectionMethod($class, $method))->getParameters()[$position];

    expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    'réinitialisation' => [ResetUserPassword::class, 'reset', 1],
    'changement' => [UpdateUserPassword::class, 'update', 1],
    'inscription' => [RegisterAccount::class, 'handle', 0],
    'lien de réinitialisation' => [User::class, 'sendPasswordResetNotification', 0],
]);
