<?php

use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Expiration des demandes : 30 jours sans réponse, ou projet qui n'est plus publié.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
});

it('fait expirer les demandes en attente depuis 30 jours, et libère la clé d\'unicité', function (): void {
    $old = MatchRequest::factory()->create();
    Carbon::setTestNow(now()->addDays(10));
    $recent = MatchRequest::factory()->create();
    $accepted = MatchRequest::factory()->status(MatchRequestStatus::Accepted)->create();

    Carbon::setTestNow(now()->addDays(MatchRequest::EXPIRATION_DAYS - 10));
    Notification::fake();

    $this->artisan('match-requests:expire')->expectsOutput('1 demande(s) expirée(s).')->assertSuccessful();

    expect($old->fresh()->status)->toBe(MatchRequestStatus::Expired)
        ->and($old->fresh()->expired_at)->not->toBeNull()
        ->and($old->fresh()->active_key)->toBeNull()
        ->and($recent->fresh()->status)->toBe(MatchRequestStatus::Pending)
        ->and($accepted->fresh()->status)->toBe(MatchRequestStatus::Accepted);

    Notification::assertNothingSent();

    // Relancer la tâche ne change rien.
    $this->artisan('match-requests:expire')->expectsOutput('0 demande(s) expirée(s).')->assertSuccessful();
});

it('fait expirer les propositions en attente sur un projet arrivé à expiration', function (): void {
    $proposal = pendingProposal();
    $contact = MatchRequest::factory()->create(['project_id' => $proposal->project_id]);

    Carbon::setTestNow(now()->addDays(Project::PUBLICATION_DAYS + 1));
    // Les demandes elles-mêmes ont plus de 30 jours : on isole le cas du projet en repoussant leur échéance.
    MatchRequest::query()->update(['expires_at' => now()->addDay()]);

    $this->artisan('match-requests:expire')->assertSuccessful();

    expect($proposal->fresh()->status)->toBe(MatchRequestStatus::Expired)
        ->and($contact->fresh()->status)->toBe(MatchRequestStatus::Pending);
});

it('fait expirer les propositions en attente à la clôture du projet, pas les autres demandes', function (): void {
    $owner = projectOwner('particulier');
    $project = publishedProjectOf($owner);
    $pending = pendingProposal(browsingProvider(), $project);
    $declined = pendingProposal(browsingProvider(), $project);
    $declined->forceFill(['status' => MatchRequestStatus::Declined, 'active_key' => null])->save();
    $other = pendingProposal(browsingProvider(), publishedProjectOf($owner));

    $this->actingAs($owner)->postJson("/api/v1/me/projects/{$project->uuid}/close")->assertOk();

    expect($pending->fresh()->status)->toBe(MatchRequestStatus::Expired)
        ->and($declined->fresh()->status)->toBe(MatchRequestStatus::Declined)
        ->and($other->fresh()->status)->toBe(MatchRequestStatus::Pending);
});

it('fait expirer les propositions en attente à la suppression du projet, en gardant l\'historique', function (): void {
    $owner = projectOwner('particulier');
    $project = publishedProjectOf($owner);
    $pending = pendingProposal(browsingProvider(), $project);
    $contact = MatchRequest::factory()->create(['author_id' => $owner->id, 'project_id' => $project->id]);

    $this->actingAs($owner)->deleteJson("/api/v1/me/projects/{$project->uuid}")->assertNoContent();

    expect($pending->fresh()->status)->toBe(MatchRequestStatus::Expired)
        ->and($pending->fresh()->project_id)->toBeNull()
        ->and($contact->fresh()->status)->toBe(MatchRequestStatus::Pending)
        ->and($contact->fresh()->project_id)->toBeNull();
});
