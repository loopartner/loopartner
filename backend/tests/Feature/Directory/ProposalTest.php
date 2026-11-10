<?php

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Notifications\MatchRequestReceivedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| POST /projects/{uuid}/proposals : un prestataire propose ses services sur un projet publié.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

it('envoie une proposition en attente et prévient le porteur du projet', function (): void {
    $owner = projectOwner('particulier');
    $project = publishedProjectOf($owner);
    $provider = browsingProvider();

    $response = $this->actingAs($provider)
        ->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload(['price_currency' => 'eur']))
        ->assertCreated()
        ->assertJsonPath('data.type', 'proposal')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.role', 'author')
        ->assertJsonPath('data.price', ['amount' => 2500, 'currency' => 'EUR'])
        ->assertJsonPath('data.project.uuid', $project->uuid)
        ->assertJsonPath('data.counterpart.account_type', 'particulier')
        ->assertJsonPath('data.counterpart.name', null)
        ->assertJsonPath('data.conversation', null)
        ->assertJsonPath('data.expires_at', '2026-12-10T09:00:00Z');

    expectNoPrivateFields($response->json());

    $matchRequest = MatchRequest::query()->sole();
    expect($matchRequest->type)->toBe(MatchRequestType::Proposal)
        ->and($matchRequest->author_id)->toBe($provider->id)
        ->and($matchRequest->recipient_id)->toBe($owner->id)
        ->and($matchRequest->project_id)->toBe($project->id)
        ->and($matchRequest->active_key)->toBe("proposal:{$provider->id}:{$project->id}");

    Notification::assertSentTo($owner, MatchRequestReceivedNotification::class);
    Notification::assertCount(1);
});

it('accepte une proposition sans estimation de prix', function (): void {
    $project = publishedProjectOf();

    $this->actingAs(browsingProvider())
        ->postJson("/api/v1/projects/{$project->uuid}/proposals", ['message' => proposalPayload()['message']])
        ->assertCreated()
        ->assertJsonPath('data.price', null);
});

describe('autorisation', function (): void {
    it('réserve la proposition aux prestataires dont le profil est publié (403)', function (Closure $makeUser): void {
        $project = publishedProjectOf();

        $this->actingAs($makeUser())
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload())
            ->assertForbidden();

        expect(MatchRequest::query()->count())->toBe(0);
    })->with([
        'prestataire sans profil' => [fn () => projectOwner('prestataire')],
        'particulier' => [fn () => projectOwner('particulier')],
        'entrepreneur' => [fn () => projectOwner('entrepreneur')],
        'rédacteur' => [fn () => projectOwner('redacteur')],
        'admin' => [fn () => projectOwner('admin')],
    ]);

    it('refuse un prestataire dont l\'email n\'est pas vérifié (403)', function (): void {
        $provider = browsingProvider();
        $provider->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($provider)
            ->postJson('/api/v1/projects/'.publishedProjectOf()->uuid.'/proposals', proposalPayload())
            ->assertForbidden();
    });

    it('cache un projet non publié derrière un 404, avant la validation', function (Closure $makeProject): void {
        $project = $makeProject();

        $this->actingAs(browsingProvider())
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", ['message' => 'court'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Projet introuvable.');
    })->with([
        'brouillon' => [fn () => Project::factory()->draft()->create()],
        'clos' => [fn () => Project::factory()->closed()->create()],
        'expiré' => [fn () => Project::factory()->expired()->create()],
    ]);

    it('répond 404 pour un projet inconnu', function (): void {
        $this->actingAs(browsingProvider())
            ->postJson('/api/v1/projects/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d/proposals', proposalPayload())
            ->assertNotFound();
    });
});

describe('validation', function (): void {
    it('refuse un message invalide (422)', function (mixed $message): void {
        $project = publishedProjectOf();

        $this->actingAs(browsingProvider())
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload(['message' => $message]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    })->with([
        'absent' => [null],
        'trop court' => ['Bonjour !'],
        'trop long' => [str_repeat('a', 5001)],
        'HTML' => ['Bonjour, <script>alert(1)</script> je suis disponible rapidement.'],
        'caractère invisible' => ["Bonjour, je suis disponible\u{202E} rapidement pour vous."],
        'tableau' => [['Bonjour']],
    ]);

    it('refuse une estimation de prix invalide (422)', function (array $price, string $field): void {
        $project = publishedProjectOf();

        $this->actingAs(browsingProvider())
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload($price))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'montant sans devise' => [['price_currency' => null], 'price_currency'],
        'devise inconnue' => [['price_currency' => 'XXX'], 'price_currency'],
        'montant nul' => [['price_amount' => 0], 'price_amount'],
        'montant négatif' => [['price_amount' => -5], 'price_amount'],
        'montant trop élevé' => [['price_amount' => 10_000_001], 'price_amount'],
        'montant non entier' => [['price_amount' => 'beaucoup'], 'price_amount'],
    ]);

    it('ignore une devise envoyée sans montant', function (): void {
        $project = publishedProjectOf();

        $this->actingAs(browsingProvider())
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", ['message' => proposalPayload()['message'], 'price_currency' => 'USD'])
            ->assertCreated()
            ->assertJsonPath('data.price', null);

        expect(MatchRequest::query()->sole()->price_currency)->toBeNull();
    });

    it('ignore les champs sensibles envoyés par le client', function (): void {
        $owner = projectOwner('particulier');
        $project = publishedProjectOf($owner);
        $provider = browsingProvider();
        $victim = User::factory()->create();
        $otherProject = publishedProjectOf();

        $uuid = $this->actingAs($provider)->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload([
            'uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            'type' => 'contact',
            'status' => 'accepted',
            'author_id' => $victim->id,
            'recipient_id' => $victim->id,
            'project_id' => $otherProject->id,
            'active_key' => 'x',
            'expires_at' => '2030-01-01T00:00:00Z',
            'responded_at' => '2026-01-01T00:00:00Z',
        ]))->assertCreated()->json('data.uuid');

        $matchRequest = MatchRequest::query()->sole();
        expect($uuid)->not->toBe('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')
            ->and($matchRequest->type)->toBe(MatchRequestType::Proposal)
            ->and($matchRequest->status)->toBe(MatchRequestStatus::Pending)
            ->and($matchRequest->author_id)->toBe($provider->id)
            ->and($matchRequest->recipient_id)->toBe($owner->id)
            ->and($matchRequest->project_id)->toBe($project->id)
            ->and($matchRequest->active_key)->toBe("proposal:{$provider->id}:{$project->id}")
            ->and($matchRequest->expires_at->toDateString())->toBe('2026-12-10')
            ->and($matchRequest->responded_at)->toBeNull();
    });
});

describe('une seule demande active par projet', function (): void {
    it('refuse une seconde proposition en attente ou acceptée sur le même projet (409)', function (MatchRequestStatus $status): void {
        $provider = browsingProvider();
        $project = publishedProjectOf();
        $first = pendingProposal($provider, $project);
        $first->forceFill(['status' => $status])->save();

        $this->actingAs($provider)
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload())
            ->assertConflict()
            ->assertJsonPath('message', 'Vous avez déjà une proposition en cours sur ce projet.');

        expect(MatchRequest::query()->count())->toBe(1);
    })->with([MatchRequestStatus::Pending, MatchRequestStatus::Accepted]);

    it('autorise une nouvelle proposition après un refus, un retrait ou une expiration', function (string $action): void {
        $provider = browsingProvider();
        $project = publishedProjectOf();
        $first = pendingProposal($provider, $project);

        $actor = $action === 'withdraw' ? $provider : $project->user;
        $this->actingAs($actor)->postJson("/api/v1/me/match-requests/{$first->uuid}/{$action}")->assertOk();

        $this->actingAs($provider)
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload())
            ->assertCreated();

        expect(MatchRequest::query()->count())->toBe(2);
    })->with(['decline', 'withdraw']);

    it('garantit l\'unicité par un index en base, même sans la vérification préalable', function (): void {
        $provider = browsingProvider();
        $project = publishedProjectOf();
        pendingProposal($provider, $project);

        expect(fn () => MatchRequest::factory()->proposalOn($project)->create(['author_id' => $provider->id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('permet à deux prestataires de proposer sur le même projet', function (): void {
        $project = publishedProjectOf();
        pendingProposal(browsingProvider(), $project);

        $this->actingAs(browsingProvider())
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload())
            ->assertCreated();
    });
});

it('plafonne les demandes en attente envoyées par un même utilisateur (409)', function (): void {
    $provider = browsingProvider();
    MatchRequest::factory()->count(MatchRequest::MAX_PENDING_SENT)->create(['author_id' => $provider->id]);

    $this->actingAs($provider)
        ->postJson('/api/v1/projects/'.publishedProjectOf()->uuid.'/proposals', proposalPayload())
        ->assertConflict()
        ->assertJsonPath('message', 'Vous avez déjà 30 demandes en attente de réponse : retirez-en une avant d\'en envoyer une nouvelle.');
});
