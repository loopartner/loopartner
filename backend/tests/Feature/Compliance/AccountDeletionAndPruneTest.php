<?php

use App\Modules\Compliance\Actions\ForgetUserCompliance;
use App\Modules\Compliance\Actions\ManageDeadline;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\Journey;
use App\Modules\Compliance\Models\RevenueEntry;
use App\Modules\Compliance\Models\Task;
use App\Modules\Compliance\Models\ThresholdAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| Suppression de compte (tout est supprimé) et minimisation des données passées
| (compliance:prune, durées de config/security.php).
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
    verifiedLegalForm();
    verifiedDeadlineRule('fr.test.cfe', ['type' => 'yearly', 'month' => 12, 'day' => 15], [], [
        ['key' => 'payer', 'title' => ['fr' => 'Payer', 'en' => null], 'service_category' => null],
    ]);
});

it('supprime toutes les données du suivi administratif avec le compte, et seulement les siennes', function (): void {
    $user = complianceUser();
    makePremium($user);
    $company = complianceCompanyOf($user);
    complianceCompanyOf($user, ['name' => 'Seconde']);
    $this->actingAs($user)->putJson("/api/v1/me/compliance/companies/{$company->uuid}/revenue/2026-10", ['lines' => ['services_commercial' => 100]])->assertOk();
    $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', ['emails_enabled' => false])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/me/compliance/calendar-feed')->assertCreated();
    $other = complianceCompanyOf();

    fromFrontend()->actingAs($user)->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

    foreach (['compliance_settings', 'compliance_calendar_feeds', 'compliance_digests'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }

    expect(Company::query()->pluck('id')->all())->toBe([$other->id])
        ->and(Deadline::query()->where('company_id', '!=', $other->id)->count())->toBe(0)
        ->and(Task::query()->where('company_id', '!=', $other->id)->count())->toBe(0)
        ->and(RevenueEntry::query()->count())->toBe(0);
});

it('efface les données par l\'Action elle-même, sans compter sur les cascades de la ligne users', function (): void {
    $user = complianceUser();
    $company = complianceCompanyOf($user);
    $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', ['emails_enabled' => false])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/me/compliance/calendar-feed')->assertCreated();

    app(ForgetUserCompliance::class)->handle($user);

    expect($user->fresh())->not->toBeNull()
        ->and(Company::query()->whereKey($company->id)->exists())->toBeFalse()
        ->and(DB::table('compliance_settings')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('compliance_calendar_feeds')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('purge les données passées au-delà de leur durée de conservation', function (): void {
    $user = complianceUser();
    $company = complianceCompanyOf($user, ['created_on' => '2019-01-01']);
    $old = app(ManageDeadline::class)->create($company, ['title' => 'Ancienne', 'due_on' => '2026-01-01']);
    $old->forceFill(['due_on' => '2023-11-12'])->save();
    $kept = app(ManageDeadline::class)->create($company, ['title' => 'Récente', 'due_on' => '2026-01-01']);
    $kept->forceFill(['due_on' => '2023-11-14'])->save();

    foreach (['2019-12' => 1, '2020-01' => 2] as $period => $amount) {
        $entry = new RevenueEntry;
        $entry->forceFill(['company_id' => $company->id, 'period' => $period.'-01', 'activity_type' => 'services_commercial', 'amount' => $amount])->save();
    }

    $alert = new ThresholdAlert;
    $alert->forceFill(['company_id' => $company->id, 'rule_code' => 'fr.test.seuil', 'year' => 2023, 'kind' => 'reached', 'level' => 80, 'reached_at' => '2023-11-01 00:00:00'])->save();

    $journey = new Journey;
    $journey->forceFill(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'rule_code' => 'fr.journey.x', 'started_at' => '2023-01-01', 'completed_at' => '2023-02-01'])->save();

    $this->artisan('compliance:prune')->assertSuccessful();

    expect(Deadline::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Deadline::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(RevenueEntry::query()->get()->map(fn (RevenueEntry $entry): string => $entry->period->format('Y-m'))->all())->toBe(['2020-01'])
        ->and(ThresholdAlert::query()->count())->toBe(0)
        ->and(Journey::query()->count())->toBe(0)
        ->and(Deadline::query()->where('company_id', $company->id)->where('source', 'rule')->count())->toBeGreaterThan(0);
});

it('annonce les durées de conservation et la prochaine purge dans la vue d\'ensemble', function (): void {
    $this->actingAs(complianceUser())->getJson('/api/v1/me/compliance')
        ->assertOk()
        ->assertJsonPath('data.retention', [
            'past_deadlines_months' => 36,
            'revenue_years' => 6,
            'next_revenue_purge' => ['on' => '2027-01-01', 'periods_before' => '2021-01'],
        ]);
});
