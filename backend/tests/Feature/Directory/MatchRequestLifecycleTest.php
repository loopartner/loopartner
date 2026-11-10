<?php

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Notifications\MatchRequestAcceptedNotification;
use App\Modules\Directory\Notifications\MatchRequestDeclinedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Cycle de vie d'une demande : acceptation (ouvre une conversation), refus, retrait.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

describe('acceptation', function (): void {
    it('ouvre une conversation à deux participants et prévient l\'auteur', function (): void {
        $owner = projectOwner('particulier');
        $provider = browsingProvider();
        $matchRequest = pendingProposal($provider, publishedProjectOf($owner));

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.responded_at', '2026-11-10T09:00:00Z')
            ->assertJsonPath('data.expires_at', null);

        $conversation = Conversation::query()->with('participants')->sole();
        $response->assertJsonPath('data.conversation.uuid', $conversation->uuid);

        expect($conversation->participants->pluck('user_id')->all())->toEqualCanonicalizing([$owner->id, $provider->id])
            ->and($conversation->match_request_id)->toBe($matchRequest->id)
            ->and($matchRequest->fresh()->active_key)->not->toBeNull();

        Notification::assertSentTo($provider, MatchRequestAcceptedNotification::class);
    });

    it('révèle le nom du porteur de projet au prestataire seulement après l\'acceptation', function (): void {
        $owner = projectOwner('particulier');
        $provider = browsingProvider();
        $matchRequest = pendingProposal($provider, publishedProjectOf($owner));

        $this->actingAs($provider)->getJson("/api/v1/me/match-requests/{$matchRequest->uuid}")
            ->assertJsonPath('data.counterpart.name', null);
        expect($this->actingAs($provider)->getJson('/api/v1/me/match-requests?box=sent')->getContent())->not->toContain($owner->name);

        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/accept")->assertOk();

        $this->actingAs($provider)->getJson("/api/v1/me/match-requests/{$matchRequest->uuid}")
            ->assertJsonPath('data.counterpart.name', $owner->name)
            ->assertJsonPath('data.counterpart.account_type', 'particulier');
    });

    it('ne révèle jamais le nom d\'un porteur de projet dont la demande a été refusée', function (): void {
        $owner = projectOwner('particulier');
        $provider = browsingProvider();
        $matchRequest = pendingProposal($provider, publishedProjectOf($owner));

        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/decline")->assertOk();

        expect($this->actingAs($provider)->getJson("/api/v1/me/match-requests/{$matchRequest->uuid}")->getContent())
            ->not->toContain($owner->name)
            ->not->toContain($owner->email);
    });

    it('refuse d\'accepter une proposition dont le projet a expiré entre-temps (409)', function (): void {
        $owner = projectOwner('particulier');
        $matchRequest = pendingProposal(browsingProvider(), publishedProjectOf($owner));

        Carbon::setTestNow(now()->addDays(61));

        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/accept")
            ->assertConflict();

        expect(Conversation::query()->count())->toBe(0);
    });
});

it('refuse une demande et prévient l\'auteur', function (): void {
    $owner = projectOwner('particulier');
    $provider = browsingProvider();
    $matchRequest = pendingProposal($provider, publishedProjectOf($owner));

    $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/decline")
        ->assertOk()
        ->assertJsonPath('data.status', 'declined');

    expect($matchRequest->fresh()->active_key)->toBeNull();
    Notification::assertSentTo($provider, MatchRequestDeclinedNotification::class);
});

it('retire une demande en attente, sans email', function (): void {
    $provider = browsingProvider();
    $matchRequest = pendingProposal($provider);
    Notification::fake();

    $this->actingAs($provider)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/withdraw")
        ->assertOk()
        ->assertJsonPath('data.status', 'withdrawn');

    expect($matchRequest->fresh()->withdrawn_at)->not->toBeNull()
        ->and($matchRequest->fresh()->active_key)->toBeNull();
    Notification::assertNothingSent();
});

describe('qui peut faire quoi', function (): void {
    it('refuse à l\'auteur d\'accepter, de refuser, de signaler ou de bloquer sa propre demande (403)', function (string $action): void {
        $provider = browsingProvider();
        $matchRequest = pendingProposal($provider);

        $this->actingAs($provider)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/{$action}", ['reason' => 'spam'])
            ->assertForbidden();

        expect($matchRequest->fresh()->status)->toBe(MatchRequestStatus::Pending);
    })->with(['accept', 'decline', 'report', 'block']);

    it('refuse au destinataire de retirer la demande (403)', function (): void {
        $owner = projectOwner('particulier');
        $matchRequest = pendingProposal(browsingProvider(), publishedProjectOf($owner));

        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/withdraw")->assertForbidden();
    });
});

it('refuse toute transition depuis un statut définitif (409)', function (MatchRequestStatus $status, string $action, string $actor): void {
    $owner = projectOwner('particulier');
    $provider = browsingProvider();
    $matchRequest = pendingProposal($provider, publishedProjectOf($owner));
    $matchRequest->forceFill(['status' => $status, 'active_key' => null])->save();

    $this->actingAs($actor === 'owner' ? $owner : $provider)
        ->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/{$action}")
        ->assertConflict()
        ->assertJsonPath('message', 'Cette demande a déjà été traitée.');

    expect($matchRequest->fresh()->status)->toBe($status);
})->with([
    'accepter une demande refusée' => [MatchRequestStatus::Declined, 'accept', 'owner'],
    'accepter une demande retirée' => [MatchRequestStatus::Withdrawn, 'accept', 'owner'],
    'accepter une demande expirée' => [MatchRequestStatus::Expired, 'accept', 'owner'],
    'refuser une demande acceptée' => [MatchRequestStatus::Accepted, 'decline', 'owner'],
    'retirer une demande acceptée' => [MatchRequestStatus::Accepted, 'withdraw', 'provider'],
    'retirer une demande refusée' => [MatchRequestStatus::Declined, 'withdraw', 'provider'],
]);

it('n\'ouvre jamais deux conversations pour une même demande', function (): void {
    $owner = projectOwner('particulier');
    $matchRequest = pendingProposal(browsingProvider(), publishedProjectOf($owner));

    $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/accept")->assertOk();
    $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/accept")->assertConflict();

    expect(Conversation::query()->count())->toBe(1);
});

describe('listes', function (): void {
    it('sépare les demandes reçues et envoyées, avec des filtres', function (): void {
        $owner = projectOwner('particulier');
        $provider = browsingProvider();
        $proposal = pendingProposal($provider, publishedProjectOf($owner));
        $declined = pendingProposal($provider, publishedProjectOf($owner));
        $declined->forceFill(['status' => MatchRequestStatus::Declined, 'active_key' => null])->save();
        MatchRequest::factory()->create();

        $this->actingAs($owner)->getJson('/api/v1/me/match-requests?box=received')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.role', 'recipient');
        $this->actingAs($owner)->getJson('/api/v1/me/match-requests?box=sent')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($provider)->getJson('/api/v1/me/match-requests?box=sent&status=pending&type=proposal')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $proposal->uuid);
        $this->actingAs($provider)->getJson('/api/v1/me/match-requests?box=sent&type=contact')
            ->assertOk()->assertJsonCount(0, 'data');

        expectNoPrivateFields($this->actingAs($owner)->getJson('/api/v1/me/match-requests?box=received')->json());
    });

    it('valide les paramètres de la liste (422)', function (string $query, string $field): void {
        $this->actingAs(projectOwner('particulier'))
            ->getJson("/api/v1/me/match-requests?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'boîte absente' => ['', 'box'],
        'boîte inconnue' => ['box=all', 'box'],
        'statut inconnu' => ['box=sent&status=deleted', 'status'],
        'type inconnu' => ['box=sent&type=spam', 'type'],
        'per_page trop grand' => ['box=sent&per_page=51', 'per_page'],
    ]);
});
