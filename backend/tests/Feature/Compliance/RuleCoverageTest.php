<?php

use App\Models\User;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Notifications\RuleCoverageNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Mise à jour annuelle des règles : rapport de couverture (GET /admin/compliance/coverage) et
| récapitulatif aux admins du 1er octobre au 30 novembre (compliance:check-rule-coverage).
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
});

/**
 * Règles vérifiées de 2026 : un statut et un seuil, sans version pour 2027.
 */
function rulesOnlyFor2026(): void
{
    ComplianceRule::factory()->verified()->legalForm('fr.legal_form.micro')->create(['valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);
    ComplianceRule::factory()->verified()->threshold('fr.test.seuil', 1_000)->create(['valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);
}

it('signale les règles sans version vérifiée pour l\'année suivante et le pays qui ne serait plus proposé', function (): void {
    rulesOnlyFor2026();
    ComplianceRule::factory()->threshold('fr.test.seuil', null)->create(['valid_from' => '2027-01-01']);

    asModerator()->getJson('/api/v1/admin/compliance/coverage')
        ->assertOk()
        ->assertJsonPath('meta.year', 2027)
        ->assertJsonPath('meta.needs_attention', true)
        ->assertJsonPath('data.0.country', 'FR')
        ->assertJsonPath('data.0.available', false)
        ->assertJsonPath('data.0.missing', ['fr.legal_form.micro', 'fr.test.seuil'])
        ->assertJsonPath('data.0.drafts', 1);
});

it('signale une règle sans date de fin vérifiée avant septembre comme à revérifier', function (): void {
    ComplianceRule::factory()->verified()->legalForm('fr.legal_form.micro')->create(['last_verified_at' => '2026-03-01 00:00:00']);
    ComplianceRule::factory()->verified()->threshold('fr.test.seuil', 1_000)->create(['last_verified_at' => '2026-10-01 00:00:00']);

    asModerator()->getJson('/api/v1/admin/compliance/coverage?year=2027')
        ->assertOk()
        ->assertJsonPath('data.0.available', true)
        ->assertJsonPath('data.0.missing', [])
        ->assertJsonPath('data.0.stale', ['fr.legal_form.micro']);
});

it('ne tient jamais compte des règles de démonstration', function (): void {
    ComplianceRule::factory()->verified()->demo()->legalForm('demo.legal_form.micro')->create(['valid_until' => '2026-12-31']);

    asModerator()->getJson('/api/v1/admin/compliance/coverage?year=2027')
        ->assertOk()
        ->assertJsonPath('data.0.missing', []);
});

it('prévient les admins avec 2FA en novembre, au plus une fois par semaine', function (): void {
    rulesOnlyFor2026();
    $admin = moderator();
    $adminWithout2fa = User::factory()->create();
    $adminWithout2fa->assignRole('admin');

    $this->artisan('compliance:check-rule-coverage')->assertSuccessful();
    $this->artisan('compliance:check-rule-coverage')->assertSuccessful();

    Notification::assertSentToTimes($admin, RuleCoverageNotification::class, 1);
    Notification::assertNotSentTo($adminWithout2fa, RuleCoverageNotification::class);

    $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
    $this->artisan('compliance:check-rule-coverage')->assertSuccessful();
    Notification::assertSentToTimes($admin, RuleCoverageNotification::class, 2);
});

it('ne fait rien hors de la période du 1er octobre au 30 novembre', function (string $date): void {
    rulesOnlyFor2026();
    $admin = moderator();
    $this->travelTo(Carbon::parse($date));

    $this->artisan('compliance:check-rule-coverage')->assertSuccessful();

    Notification::assertNotSentTo($admin, RuleCoverageNotification::class);
})->with(['2026-09-30 23:00:00', '2026-12-01 06:30:00']);

it('n\'envoie rien quand toutes les règles de l\'année suivante sont vérifiées', function (): void {
    ComplianceRule::factory()->verified()->legalForm('fr.legal_form.micro')->create(['last_verified_at' => '2026-10-15 00:00:00']);
    $admin = moderator();

    $this->artisan('compliance:check-rule-coverage')->assertSuccessful();

    Notification::assertNotSentTo($admin, RuleCoverageNotification::class);
});

it('n\'écrit dans l\'email que des nombres, jamais le contenu des règles', function (): void {
    rulesOnlyFor2026();
    $admin = moderator();

    $this->artisan('compliance:check-rule-coverage')->assertSuccessful();

    Notification::assertSentTo($admin, RuleCoverageNotification::class, function (RuleCoverageNotification $notification) use ($admin): bool {
        $mail = implode("\n", $notification->toMail($admin)->introLines);

        return str_contains($mail, 'FR : 2 règle(s)') && ! str_contains($mail, 'fr.test.seuil');
    });
});
