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

it('masque les mots de passe dans les traces d\'exception', function (string $class, string $method, int $position): void {
    $parameter = (new ReflectionMethod($class, $method))->getParameters()[$position];

    expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    'réinitialisation' => [ResetUserPassword::class, 'reset', 1],
    'changement' => [UpdateUserPassword::class, 'update', 1],
    'inscription' => [RegisterAccount::class, 'handle', 0],
    'lien de réinitialisation' => [User::class, 'sendPasswordResetNotification', 0],
]);
