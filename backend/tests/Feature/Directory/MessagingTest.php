<?php

use App\Modules\Directory\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Messagerie : conversations, messages (paginés ou nouveaux seulement), non-lus et lecture.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

describe('conversations', function (): void {
    it('liste les conversations de l\'utilisateur avec le nombre de messages non lus', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        acceptedConversation(); // conversation d'autres utilisateurs, invisible

        postMessage($provider, $conversation, 'Premier message du prestataire.');
        Carbon::setTestNow(now()->addMinute());
        postMessage($provider, $conversation, 'Second message du prestataire.');
        postMessage($owner, $conversation->fresh(), 'Réponse du porteur de projet.');

        $response = $this->actingAs($provider)->getJson('/api/v1/me/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $conversation->uuid)
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonPath('data.0.last_message.excerpt', 'Réponse du porteur de projet.')
            ->assertJsonPath('data.0.last_message.mine', false)
            ->assertJsonPath('data.0.counterpart.name', $owner->name)
            ->assertJsonPath('data.0.counterpart.account_type', 'particulier')
            ->assertJsonPath('data.0.can_send', true)
            ->assertJsonPath('data.0.blocked_by_me', false)
            ->assertJsonPath('data.0.match_request.type', 'proposal');

        expectNoPrivateFields($response->json());

        // Répondre vaut lecture des messages précédents, et ses propres messages ne sont jamais non lus.
        $this->actingAs($owner)->getJson('/api/v1/me/conversations')->assertJsonPath('data.0.unread_count', 0);
        postMessage($provider, $conversation->fresh(), 'Troisième message du prestataire.');
        $this->actingAs($owner)->getJson('/api/v1/me/conversations')->assertJsonPath('data.0.unread_count', 1);
    });

    it('présente le prestataire par son profil public, jamais par son nom ni son email', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();

        $response = $this->actingAs($owner)->getJson("/api/v1/me/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('data.counterpart.account_type', 'prestataire')
            ->assertJsonPath('data.counterpart.name', null);

        expect($response->json('data.counterpart.provider.display_name'))->not->toBeNull()
            ->and($response->getContent())->not->toContain($provider->email)
            ->and($response->getContent())->not->toContain($provider->name);
    });

    it('compte les messages non lus de toutes les conversations', function (): void {
        [$owner, $provider, $first] = acceptedConversation();
        [, , $second] = acceptedConversation($owner);

        postMessage($provider, $first);
        postMessage($provider, $first);
        postMessage($second->participants->firstWhere('user_id', '!=', $owner->id)->user, $second);

        $this->actingAs($owner)->getJson('/api/v1/me/conversations/unread')
            ->assertOk()
            ->assertExactJson(['data' => ['conversations' => 2, 'messages' => 3]]);
        $this->actingAs($provider)->getJson('/api/v1/me/conversations/unread')
            ->assertExactJson(['data' => ['conversations' => 0, 'messages' => 0]]);
    });
});

describe('messages', function (): void {
    it('envoie un message en texte brut', function (): void {
        [$owner, , $conversation] = acceptedConversation();
        $body = "Bonjour,\nvoici mon budget : < 3000 €.\tMerci !";

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", ['body' => $body])
            ->assertCreated()
            ->assertJsonPath('data.body', $body)
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.sent_at', '2026-11-10T09:00:00Z');

        expect($response->json('data'))->toHaveKeys(['uuid', 'body', 'mine', 'sent_at'])->toHaveCount(4);
        expect(Message::query()->sole()->sender_id)->toBe($owner->id);
    });

    it('refuse un message vide, trop long, en HTML ou avec des caractères invisibles (422)', function (mixed $body): void {
        [$owner, , $conversation] = acceptedConversation();

        $this->actingAs($owner)
            ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", ['body' => $body])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        expect(Message::query()->count())->toBe(0);
    })->with([
        'absent' => [null],
        'espaces' => ['   '],
        'trop long' => [str_repeat('é', 5001)],
        'HTML' => ['Regardez <a href="https://exemple.test">ici</a>'],
        'forçage de direction' => ["Mon IBAN\u{202E}est ici"],
        'caractère de contrôle' => ["Bonjour\u{0007}"],
        'tableau' => [['Bonjour']],
    ]);

    it('accepte un message de 5000 caractères', function (): void {
        [$owner, , $conversation] = acceptedConversation();

        $this->actingAs($owner)
            ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", ['body' => str_repeat('é', 5000)])
            ->assertCreated();
    });

    it('ignore l\'expéditeur, la conversation et la date envoyés par le client', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        [, , $other] = acceptedConversation();

        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", [
            'body' => 'Bonjour',
            'sender_id' => $provider->id,
            'conversation_id' => $other->id,
            'created_at' => '2020-01-01T00:00:00Z',
            'uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
        ])->assertCreated();

        $message = Message::query()->sole();
        expect($message->sender_id)->toBe($owner->id)
            ->and($message->conversation_id)->toBe($conversation->id)
            ->and($message->uuid)->not->toBe('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')
            ->and($message->created_at->toDateString())->toBe('2026-11-10');
    });

    it('pagine les messages du plus récent au plus ancien', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        foreach (range(1, 25) as $i) {
            postMessage($i % 2 === 0 ? $owner : $provider, $conversation, "Message {$i}");
        }

        $this->actingAs($owner)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages")
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.body', 'Message 25')
            ->assertJsonPath('data.0.mine', false)
            ->assertJsonPath('data.1.mine', true)
            ->assertJsonPath('meta.total', 25);

        $this->actingAs($owner)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages?page=2")
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.4.body', 'Message 1');
    });

    it('ne renvoie que les nouveaux messages avec "after", du plus ancien au plus récent', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $seen = postMessage($provider, $conversation, 'Déjà lu');
        postMessage($provider, $conversation, 'Nouveau 1');
        postMessage($owner, $conversation, 'Nouveau 2');

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages?after={$seen->uuid}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'Nouveau 1')
            ->assertJsonPath('data.1.body', 'Nouveau 2')
            ->assertJsonPath('meta.has_more', false);

        $last = $response->json('data.1.uuid');
        $this->actingAs($owner)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages?after={$last}")
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['has_more' => false]]);
    });

    it('limite les nouveaux messages à 100 et l\'indique avec has_more', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $first = postMessage($provider, $conversation, 'Point de départ');
        foreach (range(1, 101) as $i) {
            postMessage($provider, $conversation, "Message {$i}");
        }

        $this->actingAs($owner)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages?after={$first->uuid}")
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('meta.has_more', true);
    });

    it('refuse un curseur "after" inconnu ou d\'une autre conversation (422)', function (): void {
        [$owner, , $conversation] = acceptedConversation();
        [, $otherProvider, $other] = acceptedConversation();
        $foreign = postMessage($otherProvider, $other);

        foreach ([$foreign->uuid, '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'pas-un-uuid'] as $after) {
            $this->actingAs($owner)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages?after={$after}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['after']);
        }
    });
});

describe('lecture', function (): void {
    it('marque tous les messages comme lus', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        postMessage($provider, $conversation);
        postMessage($provider, $conversation);

        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/read")->assertNoContent();

        $this->actingAs($owner)->getJson('/api/v1/me/conversations')->assertJsonPath('data.0.unread_count', 0);
    });

    it('marque comme lu jusqu\'à un message, sans jamais revenir en arrière', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $first = postMessage($provider, $conversation);
        $second = postMessage($provider, $conversation);
        postMessage($provider, $conversation);

        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/read", ['up_to' => $second->uuid])->assertNoContent();
        $this->actingAs($owner)->getJson('/api/v1/me/conversations')->assertJsonPath('data.0.unread_count', 1);

        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/read", ['up_to' => $first->uuid])->assertNoContent();
        $this->actingAs($owner)->getJson('/api/v1/me/conversations')->assertJsonPath('data.0.unread_count', 1);
    });

    it('refuse un message d\'une autre conversation (422)', function (): void {
        [$owner, , $conversation] = acceptedConversation();
        [, $otherProvider, $other] = acceptedConversation();

        $this->actingAs($owner)
            ->postJson("/api/v1/me/conversations/{$conversation->uuid}/read", ['up_to' => postMessage($otherProvider, $other)->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['up_to']);
    });
});
