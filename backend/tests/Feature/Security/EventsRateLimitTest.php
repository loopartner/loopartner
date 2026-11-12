<?php

use App\Modules\Events\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Module Events : limites de débit dédiées (OWASP API4), valeurs dans config/security.php.
| Chaque dépassement donne 429 avec Retry-After et une entrée rate_limit.exceeded.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
});

it('déclare des valeurs par défaut raisonnables', function (): void {
    expect(config('security.rate_limits'))->toMatchArray([
        'event_writes' => ['per_hour' => 60, 'per_day' => 300],
        'event_submissions' => ['per_hour' => 5, 'per_day' => 15],
        'event_revisions' => ['per_hour' => 10, 'per_day' => 30],
        'event_registrations' => ['per_hour' => 20, 'per_day' => 60],
        'event_registrant_removals' => ['per_day' => 20],
        'event_announcements' => ['per_day' => 3],
        'event_moderation' => ['per_hour' => 60],
    ]);
});

it('limite les créations et modifications d\'événements (429)', function (): void {
    config(['security.rate_limits.event_writes.per_hour' => 2]);
    $organizer = eventOrganizer();

    $this->actingAs($organizer)->postJson('/api/v1/me/events', eventPayload())->assertCreated();
    $this->actingAs($organizer)->postJson('/api/v1/me/events', eventPayload())->assertCreated();
    $this->actingAs($organizer)->postJson('/api/v1/me/events', eventPayload())
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    expect(Event::query()->count())->toBe(2)
        ->and(securityLog('rate_limit.exceeded')[0]->context['route'])->toBe('POST /api/v1/me/events');
});

it('limite les soumissions à la validation (429)', function (): void {
    config(['security.rate_limits.event_submissions.per_hour' => 1]);
    $organizer = eventOrganizer();
    [$first, $second] = Event::factory()->count(2)->create(['user_id' => $organizer->id])->all();

    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$first->uuid}/submit")->assertStatus(202);
    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$second->uuid}/submit")->assertTooManyRequests();
});

it('compte les limites par utilisateur, pas globalement', function (): void {
    config(['security.rate_limits.event_writes.per_hour' => 1]);

    $this->actingAs(eventOrganizer())->postJson('/api/v1/me/events', eventPayload())->assertCreated();
    $this->actingAs(eventOrganizer())->postJson('/api/v1/me/events', eventPayload())->assertCreated();
});
