<?php

use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Limites de débit dédiées de la mise en relation (config/security.php, rate_limits) :
| demandes envoyées, messages, signalements et blocages, par utilisateur.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

it('applique les valeurs par défaut validées', function (): void {
    expect(config('security.rate_limits.match_requests'))->toBe(['per_hour' => 10, 'per_day' => 20])
        ->and(config('security.rate_limits.messages'))->toBe(['per_minute' => 10, 'per_day' => 300])
        ->and(config('security.rate_limits.reports'))->toBe(['per_day' => 10])
        ->and(config('security.rate_limits.blocks'))->toBe(['per_day' => 20])
        ->and(MatchRequest::MAX_PENDING_SENT)->toBe(30);
});

describe('demandes envoyées', function (): void {
    it('limite les demandes par heure, propositions et contacts confondus, et journalise un signe de spam', function (): void {
        config(['security.rate_limits.match_requests' => ['per_hour' => 3, 'per_day' => 20]]);
        $provider = browsingProvider();

        foreach (range(1, 3) as $i) {
            $this->actingAs($provider)->postJson('/api/v1/projects/'.publishedProjectOf()->uuid.'/proposals', proposalPayload())->assertCreated();
        }

        $this->actingAs($provider)->postJson('/api/v1/projects/'.publishedProjectOf()->uuid.'/proposals', proposalPayload())
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        expect(MatchRequest::query()->count())->toBe(3);

        $spam = securityLog('match_requests.limit_exceeded');
        expect($spam)->toHaveCount(1)
            ->and($spam[0]->level->getName())->toBe('WARNING')
            ->and($spam[0]->context)->toMatchArray(['user_id' => $provider->id, 'window' => 'hour'])
            ->and(securityLog('rate_limit.exceeded'))->toHaveCount(1);

        Carbon::setTestNow(now()->addHour()->addMinute());
        $this->actingAs($provider)->postJson('/api/v1/projects/'.publishedProjectOf()->uuid.'/proposals', proposalPayload())->assertCreated();
    });

    it('limite les demandes par jour', function (): void {
        config(['security.rate_limits.match_requests' => ['per_hour' => 10, 'per_day' => 2]]);
        $author = projectOwner('particulier');

        foreach (range(1, 2) as $i) {
            $this->actingAs($author)->postJson('/api/v1/providers/'.providerSlug(browsingProvider()).'/contact', [
                'message' => 'Bonjour, je souhaite vous confier mon projet de site.',
            ])->assertCreated();
        }

        $this->actingAs($author)->postJson('/api/v1/providers/'.providerSlug(browsingProvider()).'/contact', [
            'message' => 'Bonjour, je souhaite vous confier mon projet de site.',
        ])->assertTooManyRequests();

        expect(securityLog('match_requests.limit_exceeded')[0]->context)->toMatchArray(['window' => 'day']);
    });

    it('compte la limite par utilisateur', function (): void {
        config(['security.rate_limits.match_requests' => ['per_hour' => 1, 'per_day' => 20]]);
        $project = publishedProjectOf();

        $this->actingAs(browsingProvider())->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload())->assertCreated();
        $this->actingAs(browsingProvider())->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload())->assertCreated();
    });
});

it('limite les messages par minute puis par jour', function (): void {
    config(['security.rate_limits.messages' => ['per_minute' => 2, 'per_day' => 3]]);
    [$owner, , $conversation] = acceptedConversation();
    $url = "/api/v1/me/conversations/{$conversation->uuid}/messages";

    $this->actingAs($owner)->postJson($url, ['body' => 'Un'])->assertCreated();
    $this->actingAs($owner)->postJson($url, ['body' => 'Deux'])->assertCreated();
    $this->actingAs($owner)->postJson($url, ['body' => 'Trois'])->assertTooManyRequests();

    Carbon::setTestNow(now()->addMinutes(2));
    $this->actingAs($owner)->postJson($url, ['body' => 'Trois'])->assertCreated();

    Carbon::setTestNow(now()->addMinutes(2));
    $this->actingAs($owner)->postJson($url, ['body' => 'Quatre'])->assertTooManyRequests();

    expect(Message::query()->count())->toBe(3);
    // La lecture n'est pas concernée par cette limite.
    $this->actingAs($owner)->getJson($url)->assertOk();
});

it('limite les signalements par jour', function (): void {
    config(['security.rate_limits.reports' => ['per_day' => 2]]);
    [$owner, $provider, $conversation] = acceptedConversation();
    $messages = collect(range(1, 3))->map(fn () => postMessage($provider, $conversation));

    foreach ($messages->take(2) as $message) {
        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report", ['reason' => 'spam'])
            ->assertCreated();
    }

    $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$messages[2]->uuid}/report", ['reason' => 'spam'])
        ->assertTooManyRequests();
});

it('limite les blocages et déblocages par jour', function (): void {
    config(['security.rate_limits.blocks' => ['per_day' => 2]]);
    [$owner, , $conversation] = acceptedConversation();
    $url = "/api/v1/me/conversations/{$conversation->uuid}/block";

    $this->actingAs($owner)->postJson($url)->assertNoContent();
    $this->actingAs($owner)->deleteJson($url)->assertNoContent();
    $this->actingAs($owner)->postJson($url)->assertTooManyRequests();
});
