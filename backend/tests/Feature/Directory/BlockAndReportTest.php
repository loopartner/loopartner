<?php

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Message;
use App\Modules\Directory\Models\UserBlock;
use App\Modules\Directory\Notifications\MatchRequestDeclinedNotification;
use App\Modules\Moderation\Models\Notice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Blocage d'un utilisateur et signalement d'un message ou d'une demande.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

describe('blocage depuis une conversation', function (): void {
    it('empêche tout nouveau message, dans les deux sens', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();

        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();

        foreach ([$owner, $provider] as $sender) {
            $this->actingAs($sender)
                ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", ['body' => 'Bonjour'])
                ->assertForbidden()
                ->assertJsonPath('message', 'Vous ne pouvez pas envoyer de message dans cette conversation.');
        }

        expect(Message::query()->count())->toBe(0);

        $this->actingAs($owner)->getJson("/api/v1/me/conversations/{$conversation->uuid}")
            ->assertJsonPath('data.blocked_by_me', true)
            ->assertJsonPath('data.can_send', false);
        $this->actingAs($provider)->getJson("/api/v1/me/conversations/{$conversation->uuid}")
            ->assertJsonPath('data.blocked_by_me', false)
            ->assertJsonPath('data.can_send', false);

        // L'historique reste lisible.
        $this->actingAs($provider)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages")->assertOk();
    });

    it('empêche toute nouvelle demande entre les deux utilisateurs, quel que soit son type', function (): void {
        $owner = projectOwner('entrepreneur');
        [, $provider, $conversation] = acceptedConversation($owner);
        $otherProject = publishedProjectOf($owner);

        $this->actingAs($provider)->postJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();

        $this->actingAs($provider)->postJson("/api/v1/projects/{$otherProject->uuid}/proposals", proposalPayload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous ne pouvez pas faire de proposition sur ce projet.');
        $this->actingAs($owner)->postJson('/api/v1/providers/'.providerSlug($provider).'/contact', [
            'message' => 'Bonjour, je reviens vers vous pour un nouveau projet.',
        ])->assertForbidden()->assertJsonPath('message', 'Vous ne pouvez pas contacter ce prestataire.');

        expect(MatchRequest::query()->count())->toBe(1);
    });

    it('empêche d\'accepter une demande en attente entre deux utilisateurs bloqués (403)', function (): void {
        $owner = projectOwner('particulier');
        $provider = browsingProvider();
        $matchRequest = pendingProposal($provider, publishedProjectOf($owner));
        UserBlock::query()->insert(['blocker_id' => $provider->id, 'blocked_id' => $owner->id, 'created_at' => now()]);

        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/accept")->assertForbidden();

        expect($matchRequest->fresh()->status)->toBe(MatchRequestStatus::Pending);
    });

    it('est idempotent, journalisé une fois, et levé uniquement par celui qui a bloqué', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();

        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();
        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();
        expect(UserBlock::query()->count())->toBe(1)
            ->and(securityLog('messaging.user_blocked'))->toHaveCount(1)
            ->and(securityLog('messaging.user_blocked')[0]->context)->toMatchArray(['user_id' => $owner->id, 'blocked_user_id' => $provider->id]);

        // L'autre participant ne peut pas lever un blocage qu'il n'a pas posé.
        $this->actingAs($provider)->deleteJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();
        expect(UserBlock::query()->count())->toBe(1);

        $this->actingAs($owner)->deleteJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();
        expect(UserBlock::query()->count())->toBe(0)
            ->and(securityLog('messaging.user_unblocked'))->toHaveCount(1);

        $this->actingAs($provider)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", ['body' => 'Rebonjour'])
            ->assertCreated();
    });
});

describe('blocage depuis une demande reçue', function (): void {
    it('bloque l\'auteur et refuse la demande en attente, sans email', function (): void {
        $owner = projectOwner('particulier');
        $provider = browsingProvider();
        $matchRequest = pendingProposal($provider, publishedProjectOf($owner));
        Notification::fake();

        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/block")->assertNoContent();

        expect($matchRequest->fresh()->status)->toBe(MatchRequestStatus::Declined)
            ->and(UserBlock::isBlockedBy($provider->id, $owner->id))->toBeTrue();
        Notification::assertNotSentTo($provider, MatchRequestDeclinedNotification::class);

        $this->actingAs($provider)->postJson('/api/v1/projects/'.publishedProjectOf($owner)->uuid.'/proposals', proposalPayload())
            ->assertForbidden();
    });

    it('bloque aussi l\'auteur d\'une demande déjà traitée', function (): void {
        $owner = projectOwner('particulier');
        $provider = browsingProvider();
        $matchRequest = pendingProposal($provider, publishedProjectOf($owner));
        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/decline")->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/block")->assertNoContent();

        expect(UserBlock::isBlockedBy($provider->id, $owner->id))->toBeTrue();
    });
});

describe('signalement', function (): void {
    it('signale un message de l\'interlocuteur, avec une copie du contenu pour la modération', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $message = postMessage($provider, $conversation, 'Payez-moi directement par virement, hors plateforme.');

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report", [
                'reason' => 'off_platform',
                'comment' => 'Il me demande de payer en dehors du site.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.reason', 'off_platform')
            ->assertJsonPath('data.target_type', 'message')
            ->assertJsonPath('data.status', 'open');

        expectNoPrivateFields($response->json());
        expect($response->getContent())->not->toContain('virement');

        $report = Notice::query()->sole();
        expect($report->reporter_id)->toBe($owner->id)
            ->and($report->target_owner_id)->toBe($provider->id)
            ->and($report->target_type)->toBe('message')
            ->and($report->target_ref)->toBe($message->uuid)
            ->and($report->source_reason)->toBe('off_platform')
            ->and($report->category)->toBe('terms')
            ->and($report->content_snapshot)->toBe('Payez-moi directement par virement, hors plateforme.');

        $log = securityLog('moderation.report_created');
        expect($log)->toHaveCount(1)
            ->and($log[0]->context)->toMatchArray(['user_id' => $owner->id, 'reason' => 'off_platform', 'target_type' => 'message', 'reported_user_id' => $provider->id])
            ->and(json_encode($log[0]->context))->not->toContain('virement')->not->toContain('en dehors du site');
    });

    it('signale une demande reçue', function (): void {
        $owner = projectOwner('particulier');
        $provider = browsingProvider();
        $matchRequest = pendingProposal($provider, publishedProjectOf($owner));

        $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/report", ['reason' => 'spam'])
            ->assertCreated()
            ->assertJsonPath('data.target_type', 'match_request');

        $report = Notice::query()->sole();
        expect($report->target_type)->toBe('match_request')
            ->and($report->target_owner_id)->toBe($provider->id)
            ->and($report->content_snapshot)->toBe($matchRequest->message)
            ->and($report->explanation)->toBeNull();
    });

    it('refuse un second signalement du même contenu (409)', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $message = postMessage($provider, $conversation);
        $url = "/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report";

        $this->actingAs($owner)->postJson($url, ['reason' => 'spam'])->assertCreated();
        $this->actingAs($owner)->postJson($url, ['reason' => 'harassment'])
            ->assertConflict()
            ->assertJsonPath('message', 'Vous avez déjà signalé ce contenu.');
    });

    it('refuse de signaler son propre message (422)', function (): void {
        [$owner, , $conversation] = acceptedConversation();
        $message = postMessage($owner, $conversation);

        $this->actingAs($owner)
            ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report", ['reason' => 'spam'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    });

    it('refuse un message d\'une autre conversation (404)', function (): void {
        [$owner, , $conversation] = acceptedConversation();
        [, $otherProvider, $other] = acceptedConversation();
        $foreign = postMessage($otherProvider, $other);

        $this->actingAs($owner)
            ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$foreign->uuid}/report", ['reason' => 'spam'])
            ->assertNotFound();

        expect(Notice::query()->count())->toBe(0);
    });

    it('valide le motif et le commentaire (422)', function (array $payload, string $field): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $message = postMessage($provider, $conversation);

        $this->actingAs($owner)
            ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'motif absent' => [[], 'reason'],
        'motif hors liste' => [['reason' => 'je-n-aime-pas'], 'reason'],
        'commentaire trop long' => [['reason' => 'other', 'comment' => str_repeat('a', 1001)], 'comment'],
        'commentaire en HTML' => [['reason' => 'other', 'comment' => '<b>abus</b>'], 'comment'],
        'commentaire invisible' => [['reason' => 'other', 'comment' => "abus\u{2066}caché"], 'comment'],
    ]);

    it('ignore les champs de modération envoyés par le client', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $message = postMessage($provider, $conversation);

        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report", [
            'reason' => 'spam',
            'status' => 'closed',
            'reported_user_id' => $owner->id,
            'content_snapshot' => 'Faux contenu',
            'target_id' => 999,
        ])->assertCreated()->assertJsonPath('data.status', 'open');

        $report = Notice::query()->sole();
        expect($report->target_owner_id)->toBe($provider->id)
            ->and($report->content_snapshot)->toBe($message->body)
            ->and($report->target_ref)->toBe($message->uuid);
    });
});
