<?php

use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Notifications\MatchRequestReceivedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| POST /providers/{slug}/contact : un particulier ou un entrepreneur contacte un prestataire.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

function contactPayload(array $overrides = []): array
{
    return array_merge(['message' => 'Bonjour, votre profil correspond à ce que je recherche pour mon activité.'], $overrides);
}

it('envoie une demande de contact et prévient le prestataire', function (string $role): void {
    $author = projectOwner($role);
    $provider = browsingProvider();
    $profile = ProviderProfile::query()->where('user_id', $provider->id)->sole();

    $response = $this->actingAs($author)
        ->postJson('/api/v1/providers/'.providerSlug($provider).'/contact', contactPayload())
        ->assertCreated()
        ->assertJsonPath('data.type', 'contact')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.project', null)
        ->assertJsonPath('data.price', null)
        ->assertJsonPath('data.counterpart.account_type', 'prestataire')
        ->assertJsonPath('data.counterpart.provider.display_name', $profile->display_name)
        ->assertJsonPath('data.counterpart.provider.slug', $profile->slug)
        ->assertJsonPath('data.counterpart.name', null);

    expectNoPrivateFields($response->json());
    expect($response->json('data.counterpart.provider'))->toHaveKeys(['display_name', 'slug', 'headline', 'city', 'country', 'logo_url'])
        ->and($response->getContent())->not->toContain($provider->email)
        ->and($response->getContent())->not->toContain($provider->name);

    $matchRequest = MatchRequest::query()->sole();
    expect($matchRequest->type)->toBe(MatchRequestType::Contact)
        ->and($matchRequest->recipient_id)->toBe($provider->id)
        ->and($matchRequest->active_key)->toBe("contact:{$author->id}:{$provider->id}");

    Notification::assertSentTo($provider, MatchRequestReceivedNotification::class);
})->with(['particulier', 'entrepreneur']);

it('rattache un projet publié de l\'auteur, visible du prestataire sans le nom du porteur', function (): void {
    $author = projectOwner('entrepreneur');
    $project = publishedProjectOf($author);
    $provider = browsingProvider();

    $this->actingAs($author)
        ->postJson('/api/v1/providers/'.providerSlug($provider).'/contact', contactPayload(['project' => $project->uuid]))
        ->assertCreated()
        ->assertJsonPath('data.project.uuid', $project->uuid)
        ->assertJsonPath('data.project.title', $project->title);

    $uuid = MatchRequest::query()->sole()->uuid;

    $this->actingAs($provider)->getJson("/api/v1/me/match-requests/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.role', 'recipient')
        ->assertJsonPath('data.project.title', $project->title)
        ->assertJsonPath('data.counterpart', [
            'deleted' => false,
            'account_type' => 'entrepreneur',
            'country' => $author->country,
            'name' => null,
            'provider' => null,
        ]);
});

it('refuse un projet qui n\'est pas un projet publié de l\'auteur (422)', function (Closure $makeProject): void {
    $author = projectOwner('particulier');
    $project = $makeProject($author);

    $this->actingAs($author)
        ->postJson('/api/v1/providers/'.providerSlug(browsingProvider()).'/contact', contactPayload(['project' => $project->uuid]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project'])
        ->assertJsonPath('errors.project.0', 'Choisissez l\'un de vos projets publiés.');

    expect(MatchRequest::query()->count())->toBe(0);
})->with([
    'projet d\'un autre' => [fn () => publishedProjectOf()],
    'brouillon' => [fn ($author) => Project::factory()->draft()->create(['user_id' => $author->id])],
    'clos' => [fn ($author) => Project::factory()->closed()->create(['user_id' => $author->id])],
    'expiré' => [fn ($author) => Project::factory()->expired()->create(['user_id' => $author->id])],
]);

it('refuse un identifiant de projet mal formé (422)', function (): void {
    $this->actingAs(projectOwner('particulier'))
        ->postJson('/api/v1/providers/'.providerSlug(browsingProvider()).'/contact', contactPayload(['project' => "1' OR '1'='1"]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project']);
});

it('cache un profil non publié ou inconnu derrière un 404, avant la validation', function (): void {
    $provider = browsingProvider();
    $slug = providerSlug($provider);
    ProviderProfile::query()->where('user_id', $provider->id)->update(['status' => ProviderProfileStatus::Draft->value]);

    $this->actingAs(projectOwner('particulier'))
        ->postJson("/api/v1/providers/{$slug}/contact", [])
        ->assertNotFound()
        ->assertJsonPath('message', 'Prestataire introuvable.');

    $this->actingAs(projectOwner('particulier'))
        ->postJson('/api/v1/providers/profil-inconnu/contact', contactPayload())
        ->assertNotFound();
});

it('réserve le contact aux particuliers et aux entrepreneurs (403)', function (string $role): void {
    $user = $role === 'prestataire' ? browsingProvider() : projectOwner($role);

    $this->actingAs($user)
        ->postJson('/api/v1/providers/'.providerSlug(browsingProvider()).'/contact', contactPayload())
        ->assertForbidden();

    expect(MatchRequest::query()->count())->toBe(0);
})->with(['prestataire', 'redacteur', 'admin']);

it('refuse une seconde demande active vers le même prestataire (409)', function (): void {
    $author = projectOwner('particulier');
    $slug = providerSlug(browsingProvider());

    $this->actingAs($author)->postJson("/api/v1/providers/{$slug}/contact", contactPayload())->assertCreated();
    $this->actingAs($author)->postJson("/api/v1/providers/{$slug}/contact", contactPayload(['project' => publishedProjectOf($author)->uuid]))
        ->assertConflict()
        ->assertJsonPath('message', 'Vous avez déjà une demande en cours avec ce prestataire.');
});

it('refuse un message trop court, en HTML ou avec des caractères invisibles (422)', function (string $message): void {
    $this->actingAs(projectOwner('particulier'))
        ->postJson('/api/v1/providers/'.providerSlug(browsingProvider()).'/contact', ['message' => $message])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
})->with([
    'trop court' => 'Bonjour',
    'HTML' => 'Bonjour <img src=x onerror=alert(1)> je cherche un prestataire.',
    'invisible' => "Bonjour, je cherche\u{200B} un prestataire pour mon projet.",
]);
