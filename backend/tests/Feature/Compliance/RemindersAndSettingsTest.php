<?php

use App\Modules\Compliance\Actions\ManageDeadline;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\ThresholdAlert;
use App\Modules\Compliance\Notifications\ComplianceDigestNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Réglages et récapitulatif par email (compliance:send-reminders) : un email par utilisateur et
| par jour au plus, sans doublon, sans montant, entreprises actives seulement.
| Horloge fixe : vendredi 13 novembre 2026, 06:00 UTC.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 06:00:00');
    Notification::fake();
    verifiedLegalForm();
});

function customDeadline(Company $company, string $date, string $title = 'Rendez-vous banque'): Deadline
{
    return app(ManageDeadline::class)->create($company, ['title' => $title, 'due_on' => $date, 'notes' => 'Note privée 4 120,50 €']);
}

/**
 * Lignes de l'email du récapitulatif envoyé à l'utilisateur.
 */
function digestLines(Company $company): string
{
    $lines = '';

    Notification::assertSentTo($company->user, ComplianceDigestNotification::class, function (ComplianceDigestNotification $notification) use ($company, &$lines): bool {
        $mail = $notification->toMail($company->user);
        $lines = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

        return true;
    });

    return $lines;
}

describe('réglages', function (): void {
    it('renvoie les réglages par défaut et leurs valeurs effectives', function (): void {
        $user = complianceUser();

        $this->actingAs($user)->getJson('/api/v1/me/compliance/settings')
            ->assertOk()
            ->assertJsonPath('data.emails_enabled', true)
            ->assertJsonPath('data.reminder_offsets', [7])
            ->assertJsonPath('data.effective_threshold_levels', [80, 100])
            ->assertJsonPath('data.choices.reminder_offsets', [3, 7, 14])
            ->assertJsonPath('data.choices.max_reminder_offsets', 1);
    });

    it('laisse choisir un délai gratuit, la fréquence et couper les emails', function (): void {
        $user = complianceUser();

        $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', ['reminder_offsets' => [14], 'reminder_frequency' => 'weekly', 'emails_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.reminder_offsets', [14])
            ->assertJsonPath('data.reminder_frequency', 'weekly')
            ->assertJsonPath('data.emails_enabled', false);
    });

    it('réserve plusieurs délais et les paliers personnalisés au premium', function (array $payload, string $feature): void {
        $user = complianceUser();

        $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('feature', $feature);

        makePremium($user);
        $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', $payload)->assertOk();
    })->with([
        'plusieurs délais' => [['reminder_offsets' => [1, 7, 30]], 'custom_reminders'],
        'délai premium' => [['reminder_offsets' => [30]], 'custom_reminders'],
        'paliers personnalisés' => [['threshold_levels' => [50, 90]], 'custom_threshold_levels'],
    ]);

    it('valide les délais et les paliers', function (array $payload, string $error): void {
        $user = complianceUser();
        makePremium($user);

        $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
    })->with([
        'délai inconnu' => [['reminder_offsets' => [5]], 'reminder_offsets.0'],
        'quatre délais' => [['reminder_offsets' => [1, 3, 7, 14]], 'reminder_offsets'],
        'délai en texte' => [['reminder_offsets' => ['7']], 'reminder_offsets.0'],
        'palier hors pas' => [['threshold_levels' => [83]], 'threshold_levels.0'],
        'palier trop bas' => [['threshold_levels' => [40]], 'threshold_levels.0'],
        'palier au-delà de 100' => [['threshold_levels' => [105]], 'threshold_levels.0'],
        'fréquence inconnue' => [['reminder_frequency' => 'hourly'], 'reminder_frequency'],
        'entreprise active imposée' => [['active_company_id' => 1], 'active_company_id'],
    ]);

    it('ramène les réglages premium aux valeurs gratuites après la fin du premium, sans les effacer', function (): void {
        $user = complianceUser();
        $grant = makePremium($user);
        $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', ['reminder_offsets' => [1, 7], 'threshold_levels' => [50, 90]])->assertOk();

        $grant->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->actingAs($user)->getJson('/api/v1/me/compliance/settings')
            ->assertJsonPath('data.reminder_offsets', [1, 7])
            ->assertJsonPath('data.effective_reminder_offsets', [7])
            ->assertJsonPath('data.threshold_levels', [50, 90])
            ->assertJsonPath('data.effective_threshold_levels', [80, 100]);
    });
});

describe('récapitulatif', function (): void {
    it('rappelle une échéance à son délai, une seule fois, avec le rappel de l\'avertissement', function (): void {
        $company = complianceCompanyOf();
        customDeadline($company, '2026-11-20', 'Rendez-vous banque');
        customDeadline($company, '2026-11-21', 'Pas encore');

        $this->artisan('compliance:send-reminders')->assertSuccessful();
        $this->artisan('compliance:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($company->user, ComplianceDigestNotification::class, 1);
        $lines = digestLines($company);
        expect($lines)->toContain('20/11/2026 — Rendez-vous banque')
            ->not->toContain('Pas encore')
            ->not->toContain('4 120')
            ->not->toContain('Note privée')
            ->not->toContain('Atelier Camille')
            ->toContain('ne remplacent ni les conseils d\'un expert-comptable');
    });

    it('n\'envoie pas deux fois le même rappel les jours suivants', function (): void {
        $company = complianceCompanyOf();
        customDeadline($company, '2026-11-20');

        $this->artisan('compliance:send-reminders');
        $this->travelTo(Carbon::parse('2026-11-14 06:00:00'));
        $this->artisan('compliance:send-reminders');

        Notification::assertSentToTimes($company->user, ComplianceDigestNotification::class, 1);
    });

    it('rappelle à chaque délai premium, et au seul délai gratuit après la fin du premium', function (): void {
        $user = complianceUser();
        $grant = makePremium($user);
        $company = complianceCompanyOf($user);
        $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', ['reminder_offsets' => [1, 7]])->assertOk();
        customDeadline($company, '2026-11-20');
        customDeadline($company, '2026-11-14', 'Demain');

        $this->artisan('compliance:send-reminders');
        expect(digestLines($company))->toContain('Rendez-vous banque')->toContain('Demain');

        $grant->forceFill(['ends_at' => now()->subMinute()])->save();
        $this->travelTo(Carbon::parse('2026-11-19 06:00:00'));
        $this->artisan('compliance:send-reminders');

        Notification::assertSentToTimes($user, ComplianceDigestNotification::class, 1);
    });

    it('envoie le récapitulatif hebdomadaire le lundi, avec les échéances des 14 jours', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', ['reminder_frequency' => 'weekly'])->assertOk();
        customDeadline($company, '2026-11-20', 'Dans la semaine');
        customDeadline($company, '2026-11-30', 'Dans deux semaines');
        customDeadline($company, '2026-12-10', 'Plus tard');

        $this->artisan('compliance:send-reminders');
        Notification::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-11-16 06:00:00'));
        $this->artisan('compliance:send-reminders');

        expect(digestLines($company))->toContain('Dans la semaine')->toContain('Dans deux semaines')->not->toContain('Plus tard');
    });

    it('n\'envoie rien si les emails sont coupés, ou sans email vérifié', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        customDeadline($company, '2026-11-20');
        $this->actingAs($user)->patchJson('/api/v1/me/compliance/settings', ['emails_enabled' => false])->assertOk();

        $this->artisan('compliance:send-reminders');

        Notification::assertNothingSent();
    });

    it('ne rappelle ni une échéance faite ou ignorée, ni celle d\'une entreprise verrouillée', function (): void {
        $user = complianceUser();
        $grant = makePremium($user);
        $first = complianceCompanyOf($user);
        $locked = complianceCompanyOf($user, ['name' => 'Seconde']);
        $done = customDeadline($first, '2026-11-20', 'Déjà faite');
        $done->forceFill(['status' => 'done'])->save();
        customDeadline($locked, '2026-11-20', 'Entreprise verrouillée');
        $grant->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->artisan('compliance:send-reminders');

        Notification::assertNothingSent();
    });

    it('signale les paliers de seuil et le calendrier modifié une seule fois, sans montant', function (): void {
        ComplianceRule::factory()->verified()->threshold('fr.test.seuil', 1_000_000, ['services_commercial'])->create(['title' => ['fr' => 'Plafond de test', 'en' => null]]);
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        $this->actingAs($user)->putJson("/api/v1/me/compliance/companies/{$company->uuid}/revenue/2026-10", ['lines' => ['services_commercial' => 850_000]])->assertOk();
        $company->forceFill(['calendar_changed_at' => now()])->save();

        $this->artisan('compliance:send-reminders');
        $lines = digestLines($company);

        expect($lines)->toContain('Seuil « Plafond de test » (2026) : 80 % atteint.')
            ->toContain('Votre calendrier a été mis à jour')
            ->not->toContain('850')
            ->and(ThresholdAlert::query()->whereNull('notified_at')->count())->toBe(0)
            ->and($company->fresh()->calendar_changed_at)->toBeNull();

        $this->travelTo(Carbon::parse('2026-11-14 06:00:00'));
        $this->artisan('compliance:send-reminders');
        Notification::assertSentToTimes($user, ComplianceDigestNotification::class, 1);
    });

    it('nomme l\'entreprise quand l\'utilisateur en suit plusieurs', function (): void {
        $user = complianceUser();
        makePremium($user);
        $company = complianceCompanyOf($user);
        complianceCompanyOf($user, ['name' => 'Seconde']);
        customDeadline($company, '2026-11-20');

        $this->artisan('compliance:send-reminders');

        expect(digestLines($company))->toContain('Rendez-vous banque (Atelier Camille)');
    });

    it('annonce le 1er décembre la purge annuelle du chiffre d\'affaires le plus ancien', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user, ['created_on' => '2019-05-01']);
        $this->travelTo(Carbon::parse('2026-12-01 06:00:00'));
        $this->actingAs($user)->putJson("/api/v1/me/compliance/companies/{$company->uuid}/revenue/2020-06", ['lines' => ['services_commercial' => 100]])->assertOk();

        $this->artisan('compliance:send-reminders');

        expect(digestLines($company))->toContain('Le chiffre d\'affaires saisi pour 2020 sera supprimé le 1er janvier');
    });

    it('chiffre le récapitulatif dans la file d\'attente', function (): void {
        expect(new ComplianceDigestNotification(['deadlines' => [], 'alerts' => [], 'changed' => [], 'retention_year' => null]))
            ->toBeInstanceOf(ShouldBeEncrypted::class);
    });
});
