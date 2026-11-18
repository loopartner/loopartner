<?php

use App\Models\AiUsage;
use App\Models\User;
use App\Modules\Workspaces\Models\CopilotSuggestion;
use App\Notifications\AiBudgetAlert;
use App\Support\Ai\AiBudget;
use App\Support\Ai\AiGateway;
use App\Support\Ai\AiRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| Suivi des coûts de l'IA (décisions 34 et 62) : rapport des admins sans identité ni contenu,
| alertes de budget une fois par palier et par mois, purges (décision 65).
*/

function costRequest(): AiRequest
{
    return new AiRequest('copilot.roadmap', 'v1', 'Consignes.', '{"project":"Projet-confidentiel"}', 'test', ['type' => 'object'], 1000);
}

it('donne aux admins le suivi des coûts du mois, sans identité ni contenu', function (): void {
    $this->travelTo('2027-03-14 10:00:00');
    $user = User::factory()->create(['name' => 'Utilisatrice Secrète']);
    app(AiGateway::class)->generate($user, costRequest());
    app(AiGateway::class)->generate(User::factory()->create(), costRequest());

    $response = asModerator()->getJson('/api/v1/admin/ai/usage?month=2027-03')->assertOk();

    $response->assertJsonPath('data.month', '2027-03')
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.totals.requests', 2)
        ->assertJsonPath('data.totals.users', 2)
        ->assertJsonPath('data.days.0.day', '2027-03-14')
        ->assertJsonPath('data.days.0.feature', 'copilot.roadmap')
        ->assertJsonPath('data.budget.limit_micros', 50_000_000);

    $body = (string) $response->getContent();
    expect($body)->not->toContain('Secrète')
        ->and($body)->not->toContain($user->email)
        ->and($body)->not->toContain('Projet-confidentiel')
        ->and($body)->not->toContain('"user_id"');

    asModerator()->getJson('/api/v1/admin/ai/usage?month=mars')->assertUnprocessable();
});

it('réserve le suivi des coûts aux admins avec 2FA de session', function (): void {
    $this->getJson('/api/v1/admin/ai/usage')->assertUnauthorized();
    $this->actingAs(projectOwner('particulier'))->getJson('/api/v1/admin/ai/usage')->assertNotFound();
    asModerator(tap(userWithTwoFactor(), fn (User $user) => $user->assignRole('redacteur')))->getJson('/api/v1/admin/ai/usage')->assertNotFound();
});

it('refuse le suivi des coûts à un admin dont la session n\'a pas passé la 2FA (403)', function (): void {
    fromFrontend()->actingAs(moderator())->getJson('/api/v1/admin/ai/usage')->assertForbidden();
});

it('alerte les admins une fois à 80 % puis une fois à 100 % du budget du mois', function (): void {
    Notification::fake();
    $admin = moderator();
    $adminWithout2fa = User::factory()->create();
    $adminWithout2fa->assignRole('admin');
    config(['ai.budgets.global_monthly_cents' => 1, 'ai.budgets.user_monthly_cents' => 100]); // 10 000 micros
    $period = now('UTC')->format('Y-m');
    DB::table('ai_budgets')->insert(['scope_key' => 'global:month', 'period' => $period, 'spent_micros' => 7_000, 'created_at' => now(), 'updated_at' => now()]);
    $user = User::factory()->create();
    $budget = app(AiBudget::class);

    // Réglage d'un appel : 1 600 micros de plus (80 % franchi).
    $usage = $budget->reserve($user, costRequest(), 'fake', 'fake-model');
    $budget->settle($usage, null, 2000, 1925);
    Notification::assertSentTo($admin, AiBudgetAlert::class, fn (AiBudgetAlert $alert): bool => $alert->percent === 80 && $alert->month === $period);
    Notification::assertNotSentTo($adminWithout2fa, AiBudgetAlert::class);

    // Un appel de plus sous 100 % : pas de nouvelle alerte.
    DB::table('ai_budgets')->where('scope_key', 'global:month')->update(['spent_micros' => 9_000]);
    $usage = $budget->reserve($user, new AiRequest('x', 'v1', 's', 'c', 't', ['type' => 'object'], 1), 'fake', 'fake-model');
    $budget->settle($usage, null, 100, 0);
    Notification::assertSentToTimes($admin, AiBudgetAlert::class, 1);

    // 100 % : seconde alerte, une seule fois.
    config(['ai.budgets.global_monthly_cents' => 2]); // 20 000 micros, déjà alerté à 80 %
    DB::table('ai_budgets')->where('scope_key', 'global:month')->update(['spent_micros' => 19_900, 'reserved_micros' => 0]);
    $usage = $budget->reserve($user, new AiRequest('x', 'v1', 's', 'c', 't', ['type' => 'object'], 1), 'fake', 'fake-model');
    $budget->settle($usage, null, 1000, 200);
    Notification::assertSentTo($admin, AiBudgetAlert::class, fn (AiBudgetAlert $alert): bool => $alert->percent === 100);
    Notification::assertSentToTimes($admin, AiBudgetAlert::class, 2);

    expect(securityLog('ai.budget_alert'))->toHaveCount(2)
        ->and((new AiBudgetAlert($period, 100))->notificationCategory())->toBe('ai.budget_alerts');
});

it('libère les réservations abandonnées et purge le journal des appels au bout de 13 mois', function (): void {
    $this->travelTo('2027-03-14 10:00:00');
    $user = User::factory()->create();
    $budget = app(AiBudget::class);

    $abandoned = $budget->reserve($user, costRequest(), 'fake', 'fake-model');
    $this->travelTo('2027-03-14 12:00:00');
    $this->artisan('ai:prune')->assertSuccessful();

    expect($abandoned->fresh()?->status)->toBe(AiUsage::STATUS_FAILED)
        ->and($abandoned->fresh()?->failure_reason)->toBe('abandoned')
        ->and(DB::table('ai_budgets')->sum('reserved_micros'))->toEqual(0);

    $this->travelTo('2028-05-02 10:00:00');
    $this->artisan('ai:prune')->assertSuccessful();

    expect(AiUsage::query()->count())->toBe(0)
        ->and(DB::table('ai_budgets')->where('scope_key', 'like', 'user:%')->count())->toBe(0)
        ->and(DB::table('ai_budgets')->where('scope_key', 'global:month')->count())->toBe(1)
        ->and(DB::table('ai_usage_stats')->count())->toBe(1);
});

it('purge les suggestions du copilote 90 jours après leur génération', function (): void {
    $workspace = workspaceOf(workspaceOwner());
    $make = fn (string $createdAt) => (new CopilotSuggestion)->forceFill([
        'uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'kind' => 'roadmap', 'status' => 'applied',
        'content' => ['steps' => []], 'prompt_version' => 'v1', 'provider' => 'fake', 'model' => 'fake-model',
        'created_at' => $createdAt, 'updated_at' => $createdAt,
    ])->save();

    $this->travelTo('2027-06-01 10:00:00');
    $make('2027-03-01 09:00:00');
    $make('2027-03-10 09:00:00');

    $this->artisan('workspaces:prune')->expectsOutputToContain('1 suggestion')->assertSuccessful();

    expect(CopilotSuggestion::query()->count())->toBe(1);
});
