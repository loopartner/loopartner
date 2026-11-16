<?php

use App\Models\User;
use App\Modules\Directory\Actions\BlockUser;
use App\Modules\Directory\Actions\GetUserModerationSignals;
use App\Modules\Directory\Actions\IsBlockedBetween;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Notice;
use Illuminate\Support\Facades\Notification;

/*
| Actions publiques du module Directory, en lecture seule, pour les autres modules (Events).
*/

beforeEach(fn () => Notification::fake());

it('indique un blocage entre deux utilisateurs, dans les deux sens', function (): void {
    [$blocker, $blocked, $other] = User::factory()->count(3)->create()->all();
    app(BlockUser::class)->handle($blocker, $blocked->id);

    $action = app(IsBlockedBetween::class);

    expect($action->handle($blocker->id, $blocked->id))->toBeTrue()
        ->and($action->handle($blocked->id, $blocker->id))->toBeTrue()
        ->and($action->handle($blocker->id, $other->id))->toBeFalse();
});

it('compte les signalements reçus et les blocages subis, sans aucun détail', function (): void {
    [$owner, $provider, $conversation] = acceptedConversation();
    $message = postMessage($provider, $conversation);

    $this->actingAs($owner)
        ->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages/{$message->uuid}/report", ['reason' => 'spam'])
        ->assertCreated();
    Notice::query()->firstOrFail()->forceFill(['status' => NoticeStatus::Decided, 'closed_at' => now(), 'active_key' => null])->save();
    $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/block")->assertNoContent();
    app(BlockUser::class)->handle(User::factory()->create(), $provider->id);

    expect(app(GetUserModerationSignals::class)->handle($provider->id))->toBe([
        'reports_received' => 1,
        'reports_open' => 0,
        'blocked_by_users' => 2,
    ])->and(app(GetUserModerationSignals::class)->handle($owner->id))->toBe([
        'reports_received' => 0,
        'reports_open' => 0,
        'blocked_by_users' => 0,
    ]);
});
