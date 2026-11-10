<?php

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\ReportStatus;
use App\Modules\Directory\Models\AbuseReport;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| Minimisation des données (matching:prune) : durées de conservation de config/security.php.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

it('conserve 12 mois par défaut chaque type de données', function (): void {
    expect(config('security.retention'))->toBe([
        'orphaned_conversations_months' => 12,
        'closed_match_requests_months' => 12,
        'abuse_reports_months' => 12,
    ]);
});

it('supprime les demandes refusées, retirées ou expirées au-delà de la durée de conservation', function (): void {
    $closed = collect([MatchRequestStatus::Declined, MatchRequestStatus::Withdrawn, MatchRequestStatus::Expired])
        ->map(fn (MatchRequestStatus $status) => MatchRequest::factory()->status($status)->create());
    $pending = MatchRequest::factory()->create();
    [, , $conversation] = acceptedConversation();

    Carbon::setTestNow(now()->addMonths(12)->addDay());
    $recent = MatchRequest::factory()->status(MatchRequestStatus::Declined)->create();

    $this->artisan('matching:prune')->assertSuccessful();

    foreach ($closed as $matchRequest) {
        $this->assertModelMissing($matchRequest);
    }

    // Les demandes acceptées restent tant que leur conversation existe.
    $this->assertModelExists($pending);
    $this->assertModelExists($recent);
    $this->assertModelExists($conversation->matchRequest);
});

it('respecte une durée de conservation réglée dans la configuration', function (): void {
    config(['security.retention.closed_match_requests_months' => 3]);
    $declined = MatchRequest::factory()->status(MatchRequestStatus::Declined)->create();

    Carbon::setTestNow(now()->addMonths(2));
    $this->artisan('matching:prune')->assertSuccessful();
    $this->assertModelExists($declined);

    Carbon::setTestNow(now()->addMonths(2));
    $this->artisan('matching:prune')->assertSuccessful();
    $this->assertModelMissing($declined);
});

it('supprime les conversations orphelines au-delà de la durée de conservation, avec messages et demande', function (): void {
    [$owner, $provider, $conversation] = acceptedConversation();
    postMessage($provider, $conversation);
    [, , $kept] = acceptedConversation();

    $this->actingAs($owner)->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

    Carbon::setTestNow(now()->addMonths(12)->subDay());
    $this->artisan('matching:prune')->assertSuccessful();
    $this->assertModelExists($conversation);

    Carbon::setTestNow(now()->addDays(2));
    $this->artisan('matching:prune')->assertSuccessful();

    $this->assertModelMissing($conversation);
    expect(Message::query()->where('conversation_id', $conversation->id)->count())->toBe(0)
        ->and(MatchRequest::query()->whereKey($conversation->match_request_id)->exists())->toBeFalse();
    $this->assertModelExists($kept);
});

it('efface la copie du contenu signalé, puis supprime les signalements clos, au-delà de la durée de conservation', function (): void {
    $open = AbuseReport::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'target_type' => 'message', 'target_id' => 1, 'reason' => 'spam',
        'comment' => 'Commentaire', 'content_snapshot' => 'Contenu signalé', 'status' => 'open',
    ]);
    $closed = AbuseReport::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'target_type' => 'message', 'target_id' => 2, 'reason' => 'spam',
        'content_snapshot' => 'Contenu signalé', 'status' => ReportStatus::Closed->value, 'closed_at' => now(),
    ]);

    Carbon::setTestNow(now()->addMonths(12)->addDay());
    $recent = AbuseReport::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'target_type' => 'message', 'target_id' => 3, 'reason' => 'spam',
        'content_snapshot' => 'Contenu récent', 'status' => 'open',
    ]);

    $this->artisan('matching:prune')->assertSuccessful();

    expect($open->fresh()->content_snapshot)->toBeNull()
        ->and($open->fresh()->comment)->toBeNull()
        ->and($open->fresh()->status)->toBe(ReportStatus::Open)
        ->and($recent->fresh()->content_snapshot)->toBe('Contenu récent');
    $this->assertModelMissing($closed);
});
