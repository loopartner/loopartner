<?php

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\Message;
use App\Modules\Directory\Models\UserBlock;
use App\Modules\Moderation\Models\Notice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Autorisation de la mise en relation et de la messagerie (OWASP API1 BOLA, API5 BFLA).
|
| Seuls les deux participants voient une demande ou une conversation : tout autre utilisateur,
| quel que soit son rôle (admin compris), reçoit 404 sur chaque endpoint, et rien n'est modifié.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

/**
 * Une demande en attente, une conversation et un message entre deux autres utilisateurs.
 *
 * @return array{request: string, conversation: string, message: string}
 */
function foreignMatchingData(): array
{
    [, $provider, $conversation] = acceptedConversation();
    $message = postMessage($provider, $conversation, 'Message privé entre deux autres utilisateurs.');

    return [
        'request' => pendingProposal()->uuid,
        'conversation' => $conversation->uuid,
        'message' => $message->uuid,
    ];
}

/**
 * Endpoints visant un objet : [méthode, URL, corps].
 *
 * @param  array{request: string, conversation: string, message: string}  $ids
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function objectEndpoints(array $ids): array
{
    $request = "/api/v1/me/match-requests/{$ids['request']}";
    $conversation = "/api/v1/me/conversations/{$ids['conversation']}";

    return [
        'voir la demande' => ['GET', $request, []],
        'accepter' => ['POST', "{$request}/accept", []],
        'refuser' => ['POST', "{$request}/decline", []],
        'retirer' => ['POST', "{$request}/withdraw", []],
        'signaler la demande' => ['POST', "{$request}/report", ['reason' => 'spam']],
        'bloquer depuis la demande' => ['POST', "{$request}/block", []],
        'voir la conversation' => ['GET', $conversation, []],
        'lire les messages' => ['GET', "{$conversation}/messages", []],
        'lire les nouveaux messages' => ['GET', "{$conversation}/messages?after={$ids['message']}", []],
        'écrire' => ['POST', "{$conversation}/messages", ['body' => 'Intrusion']],
        'marquer comme lu' => ['POST', "{$conversation}/read", []],
        'bloquer' => ['POST', "{$conversation}/block", []],
        'débloquer' => ['DELETE', "{$conversation}/block", []],
        'signaler un message' => ['POST', "{$conversation}/messages/{$ids['message']}/report", ['reason' => 'spam']],
    ];
}

it('cache chaque demande, conversation et message aux autres utilisateurs, quel que soit leur rôle (404)', function (Closure $makeUser): void {
    $ids = foreignMatchingData();
    $outsider = $makeUser();

    foreach (objectEndpoints($ids) as $label => [$method, $url, $body]) {
        $response = $this->actingAs($outsider)->json($method, $url, $body);

        expect($response->status())->toBe(404, "{$label} : {$response->status()}");
        expect($response->getContent())->not->toContain('Message privé');
    }

    expect(Message::query()->count())->toBe(1)
        ->and(Notice::query()->count())->toBe(0)
        ->and(UserBlock::query()->count())->toBe(0);
})->with([
    'particulier' => [fn () => projectOwner('particulier')],
    'entrepreneur' => [fn () => projectOwner('entrepreneur')],
    'prestataire' => [fn () => browsingProvider()],
    'rédacteur' => [fn () => projectOwner('redacteur')],
    'admin' => [fn () => projectOwner('admin')],
]);

it('n\'inclut jamais les demandes ni les conversations des autres dans les listes', function (): void {
    foreignMatchingData();
    $outsider = projectOwner('particulier');

    foreach (['received', 'sent'] as $box) {
        $this->actingAs($outsider)->getJson("/api/v1/me/match-requests?box={$box}")->assertOk()->assertJsonCount(0, 'data');
    }
    $this->actingAs($outsider)->getJson('/api/v1/me/conversations')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($outsider)->getJson('/api/v1/me/conversations/unread')
        ->assertExactJson(['data' => ['conversations' => 0, 'messages' => 0]]);
});

it('exige un email vérifié sur chaque endpoint de la mise en relation (403)', function (): void {
    $ids = foreignMatchingData();
    $user = projectOwner('particulier', verified: false);

    $endpoints = [
        ...objectEndpoints($ids),
        'proposer' => ['POST', '/api/v1/projects/'.publishedProjectOf()->uuid.'/proposals', proposalPayload()],
        'contacter' => ['POST', '/api/v1/providers/'.providerSlug(browsingProvider()).'/contact', ['message' => str_repeat('a', 30)]],
        'lister les demandes' => ['GET', '/api/v1/me/match-requests?box=sent', []],
        'lister les conversations' => ['GET', '/api/v1/me/conversations', []],
        'compter les non-lus' => ['GET', '/api/v1/me/conversations/unread', []],
    ];

    foreach ($endpoints as $label => [$method, $url, $body]) {
        expect($this->actingAs($user)->json($method, $url, $body)->status())->toBe(403, $label);
    }
});

describe('utilisateur bloqué', function (): void {
    it('garde l\'accès en lecture à l\'historique, mais ne peut plus écrire, accepter ni relancer', function (): void {
        $owner = projectOwner('entrepreneur');
        $provider = browsingProvider();
        [, , $conversation] = acceptedConversation($owner, $provider);
        $message = postMessage($owner, $conversation);
        $pending = pendingProposal($provider, publishedProjectOf($owner));

        // Le porteur de projet bloque le prestataire : on teste tout du point de vue du bloqué.
        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();
        $c = "/api/v1/me/conversations/{$conversation->uuid}";

        $this->actingAs($provider)->getJson($c)->assertOk()->assertJsonPath('data.can_send', false);
        $this->actingAs($provider)->getJson("{$c}/messages")->assertOk();
        $this->actingAs($provider)->postJson("{$c}/read")->assertNoContent();
        $this->actingAs($provider)->postJson("{$c}/messages", ['body' => 'Relance'])->assertForbidden();
        $this->actingAs($provider)->getJson("/api/v1/me/match-requests/{$pending->uuid}")->assertOk();
        $this->actingAs($provider)->postJson("/api/v1/me/match-requests/{$pending->uuid}/withdraw")->assertOk();
        $this->actingAs($provider)->postJson('/api/v1/projects/'.publishedProjectOf($owner)->uuid.'/proposals', proposalPayload())->assertForbidden();

        // Le bloqué ne peut pas lever le blocage posé par l'autre ; il peut signaler un message.
        $this->actingAs($provider)->deleteJson("{$c}/block")->assertNoContent();
        expect(UserBlock::isBlockedBy($provider->id, $owner->id))->toBeTrue();
        $this->actingAs($provider)->postJson("{$c}/messages/{$message->uuid}/report", ['reason' => 'harassment'])->assertCreated();

        // Dans l'autre sens aussi : celui qui a bloqué ne peut plus contacter le bloqué.
        $this->actingAs($owner)->postJson('/api/v1/providers/'.providerSlug($provider).'/contact', [
            'message' => 'Bonjour, finalement je reviens vers vous.',
        ])->assertForbidden();
        $this->actingAs($owner)->postJson("{$c}/messages", ['body' => 'Relance'])->assertForbidden();
    });

    it('ne peut pas faire accepter une demande à celui qu\'il a bloqué', function (): void {
        $owner = projectOwner('particulier');
        $provider = browsingProvider();
        $matchRequest = pendingProposal($provider, publishedProjectOf($owner));
        UserBlock::query()->insert(['blocker_id' => $owner->id, 'blocked_id' => $provider->id, 'created_at' => now()]);

        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/accept")->assertForbidden();
        expect($matchRequest->fresh()->status)->toBe(MatchRequestStatus::Pending);
    });
});

it('journalise les refus d\'accès sans contenu de message', function (): void {
    $ids = foreignMatchingData();

    $this->actingAs(projectOwner('particulier'))->getJson("/api/v1/me/conversations/{$ids['conversation']}/messages")->assertNotFound();

    expect(json_encode(array_map(fn ($record) => $record->context, securityLog())))->not->toContain('Message privé');
});

it('ne laisse jamais un utilisateur agir au nom d\'un autre en changeant d\'identifiant', function (): void {
    [$owner, $provider, $conversation] = acceptedConversation();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", [
        'body' => 'Message usurpé',
        'sender_id' => $owner->id,
        'user_id' => $owner->id,
    ])->assertNotFound();

    expect(Message::query()->count())->toBe(0);
    expect($provider->id)->not->toBe($intruder->id);
});
