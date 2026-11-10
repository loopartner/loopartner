<?php

use App\Models\User;
use App\Modules\Accounts\Actions\DeleteAccount;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\AbuseReport;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\ConversationParticipant;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Message;
use App\Modules\Directory\Models\UserBlock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Suppression de compte (RGPD) : demandes non acceptées supprimées, conversations conservées pour
| l'autre participant sans lien avec le compte supprimé, en lecture seule, puis purgées.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

function deleteAccount(User $user): void
{
    test()->actingAs($user)->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();
}

it('supprime les demandes non acceptées, envoyées ou reçues, du compte supprimé', function (): void {
    $owner = projectOwner('particulier');
    $provider = browsingProvider();
    $received = pendingProposal($provider, publishedProjectOf($owner));
    $declined = pendingProposal($provider, publishedProjectOf($owner));
    $declined->forceFill(['status' => MatchRequestStatus::Declined, 'active_key' => null])->save();
    $sent = MatchRequest::factory()->create(['author_id' => $owner->id]);
    $unrelated = MatchRequest::factory()->create();

    deleteAccount($owner);

    $this->assertModelMissing($received);
    $this->assertModelMissing($declined);
    $this->assertModelMissing($sent);
    $this->assertModelExists($unrelated);

    $this->actingAs($provider)->getJson('/api/v1/me/match-requests?box=sent')->assertJsonCount(0, 'data');
});

it('conserve la conversation pour l\'autre participant, anonymisée et en lecture seule', function (): void {
    [$owner, $provider, $conversation] = acceptedConversation();
    postMessage($owner, $conversation, 'Message du porteur de projet');
    postMessage($provider, $conversation, 'Réponse du prestataire');
    $ownerName = $owner->name;

    deleteAccount($owner);

    $conversation->refresh();
    expect($conversation->orphaned_at?->toDateString())->toBe('2026-11-10')
        ->and(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(2)
        ->and(Message::query()->whereNull('sender_id')->count())->toBe(1)
        ->and(ConversationParticipant::query()->where('conversation_id', $conversation->id)->whereNull('user_id')->count())->toBe(1)
        ->and($conversation->matchRequest->author_id)->toBe($provider->id)
        ->and($conversation->matchRequest->recipient_id)->toBeNull()
        ->and($conversation->matchRequest->active_key)->toBeNull();

    $response = $this->actingAs($provider)->getJson("/api/v1/me/conversations/{$conversation->uuid}")
        ->assertOk()
        ->assertJsonPath('data.counterpart.deleted', true)
        ->assertJsonPath('data.counterpart.name', null)
        ->assertJsonPath('data.can_send', false);
    expect($response->getContent())->not->toContain($ownerName);

    $this->actingAs($provider)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.body', 'Message du porteur de projet')
        ->assertJsonPath('data.1.mine', false);

    $this->actingAs($provider)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", ['body' => 'Toujours là ?'])
        ->assertForbidden();
    $this->actingAs($provider)->postJson("/api/v1/me/conversations/{$conversation->uuid}/block")
        ->assertConflict();

    $this->actingAs($provider)->getJson("/api/v1/me/match-requests/{$conversation->matchRequest->uuid}")
        ->assertOk()
        ->assertJsonPath('data.counterpart.deleted', true);
});

it('supprime tout de suite une conversation dont les deux participants ont supprimé leur compte', function (): void {
    [$owner, $provider, $conversation] = acceptedConversation();
    postMessage($owner, $conversation);

    deleteAccount($owner);
    // Second compte supprimé par l'Action : la session de test a été fermée par la première suppression.
    app(DeleteAccount::class)->handle($provider->fresh());

    $this->assertModelMissing($conversation);
    expect(Message::query()->count())->toBe(0)
        ->and(MatchRequest::query()->count())->toBe(0);
});

it('supprime les blocages et anonymise les signalements du compte supprimé', function (): void {
    [$owner, $provider, $conversation] = acceptedConversation();
    $message = postMessage($provider, $conversation, 'Contenu signalé');
    $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();
    $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report", ['reason' => 'spam'])
        ->assertCreated();

    deleteAccount($provider);

    expect(UserBlock::query()->count())->toBe(0);

    $report = AbuseReport::query()->sole();
    expect($report->reported_user_id)->toBeNull()
        ->and($report->reporter_id)->toBe($owner->id)
        ->and($report->content_snapshot)->toBe('Contenu signalé');
});

it('annule toute la suppression si le traitement de la messagerie échoue', function (): void {
    [$owner, , $conversation] = acceptedConversation();

    // Simule une erreur dans l'écouteur : la transaction de DeleteAccount est annulée.
    Conversation::saving(function (): void {
        throw new RuntimeException('Échec simulé');
    });

    $this->actingAs($owner)->deleteJson('/api/v1/me', ['password' => 'password'])->assertServerError();

    $this->assertModelExists($owner);
    expect(MatchRequest::query()->count())->toBe(1)
        ->and($conversation->fresh()->orphaned_at)->toBeNull();
});
