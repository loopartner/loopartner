<?php

use App\Modules\Directory\Models\MatchRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| Les messages et les demandes sont des données personnelles : leur contenu n'apparaît dans aucun
| log (journal security compris), et aucune réponse n'expose d'identifiant interne ni de coordonnées.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

it('n\'écrit jamais le contenu d\'un message, d\'une demande ou d\'un signalement dans les logs', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $owner = projectOwner('particulier');
    $provider = browsingProvider();
    $project = publishedProjectOf($owner);

    // Proposition, acceptation, messages, signalement, blocage, et une limite dépassée.
    $this->actingAs($provider)->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload([
        'message' => 'Proposition contenant marqueur-proposition-secret à ne jamais journaliser.',
    ]))->assertCreated();
    $matchRequest = MatchRequest::query()->sole();
    $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/accept")->assertOk();
    $conversation = $matchRequest->fresh()->conversation;

    $uuid = $this->actingAs($provider)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", [
        'body' => 'Message contenant marqueur-message-secret.',
    ])->assertCreated()->json('data.uuid');
    $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", [
        'body' => '<b>marqueur-html-refuse</b>',
    ])->assertUnprocessable();
    $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$uuid}/report", [
        'reason' => 'other',
        'comment' => 'Commentaire contenant marqueur-commentaire-secret.',
    ])->assertCreated();
    $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();

    config(['security.rate_limits.match_requests' => ['per_hour' => 1, 'per_day' => 20]]);
    $this->actingAs($provider)->postJson('/api/v1/projects/'.publishedProjectOf()->uuid.'/proposals', proposalPayload([
        'message' => 'Relance contenant marqueur-spam-secret pour tester la limite.',
    ]))->assertTooManyRequests();

    $everything = implode("\n", $logged)."\n".json_encode(array_map(fn ($record) => [$record->message, $record->context], securityLog()));

    expect($logged)->not->toBeEmpty();
    foreach (['marqueur-proposition-secret', 'marqueur-message-secret', 'marqueur-html-refuse', 'marqueur-commentaire-secret', 'marqueur-spam-secret', $owner->email, $provider->email, $owner->name] as $secret) {
        expect($everything)->not->toContain($secret);
    }

    // Les événements attendus sont bien journalisés, avec des identifiants et sans contenu.
    expect(securityLog('match_requests.created'))->toHaveCount(1)
        ->and(securityLog('moderation.report_created'))->toHaveCount(1)
        ->and(securityLog('messaging.user_blocked'))->toHaveCount(1)
        ->and(securityLog('match_requests.limit_exceeded'))->toHaveCount(1);
});

it('ne renvoie le contenu refusé dans aucun message d\'erreur de validation', function (): void {
    [$owner, , $conversation] = acceptedConversation();

    $response = $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", [
        'body' => "<script>marqueur-xss</script>\u{202E}",
    ])->assertUnprocessable();

    expect($response->getContent())->not->toContain('marqueur-xss');
});

it('n\'expose ni identifiant interne ni coordonnées dans les réponses de la mise en relation', function (): void {
    [$owner, $provider, $conversation] = acceptedConversation();
    $message = postMessage($provider, $conversation);
    $pending = pendingProposal($provider, publishedProjectOf($owner));

    $responses = [
        $this->actingAs($owner)->getJson('/api/v1/me/match-requests?box=received'),
        $this->actingAs($provider)->getJson('/api/v1/me/match-requests?box=sent'),
        $this->actingAs($owner)->getJson("/api/v1/me/match-requests/{$pending->uuid}"),
        $this->actingAs($owner)->getJson('/api/v1/me/conversations'),
        $this->actingAs($provider)->getJson("/api/v1/me/conversations/{$conversation->uuid}"),
        $this->actingAs($owner)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages"),
        $this->actingAs($owner)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages?after={$message->uuid}"),
        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report", ['reason' => 'spam']),
    ];

    foreach ($responses as $response) {
        expect($response->status())->toBeLessThan(300);
        expectNoPrivateFields($response->json());
        expect($response->getContent())
            ->not->toContain($owner->email)
            ->not->toContain($provider->email)
            ->not->toContain($provider->name);
    }
});
