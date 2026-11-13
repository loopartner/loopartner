<?php

use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Modules\Compliance\Jobs\RefreshCompanyCalendars;
use App\Modules\Compliance\Jobs\RefreshCountryCalendars;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\Task;
use App\Modules\Compliance\Support\CalendarGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| Génération et recalcul du calendrier (CalendarGenerator) : règles vérifiées seulement,
| récurrences, conditions, et jamais de modification de ce que l'utilisateur a fait ou ignoré.
| Horloge fixe : 13 novembre 2026, fenêtre de 15 mois (jusqu'au 13 février 2028).
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
    verifiedLegalForm();
});

/**
 * @return list<string>
 */
function dueDates(Company $company, ?string $code = null): array
{
    return Deadline::query()
        ->where('company_id', $company->id)
        ->when($code !== null, fn ($query) => $query->where('rule_code', $code))
        ->orderBy('due_on')
        ->get()
        ->map(fn (Deadline $deadline): string => $deadline->due_on->toDateString())
        ->all();
}

describe('récurrences', function (): void {
    it('déroule une échéance annuelle sur la fenêtre', function (): void {
        verifiedDeadlineRule('fr.test.annuelle', ['type' => 'yearly', 'month' => 12, 'day' => 15]);

        expect(dueDates(complianceCompanyOf()))->toBe(['2026-12-15', '2027-12-15']);
    });

    it('ramène un jour trop grand au dernier jour du mois (mensuelle, février)', function (): void {
        verifiedDeadlineRule('fr.test.mensuelle', ['type' => 'monthly', 'day' => 31]);

        $dates = dueDates(complianceCompanyOf());
        expect($dates[0])->toBe('2026-11-30')
            ->and($dates)->toContain('2027-02-28')
            ->and($dates)->toContain('2027-04-30')
            ->and(end($dates))->toBe('2028-01-31');
    });

    it('déroule une échéance trimestrielle', function (): void {
        verifiedDeadlineRule('fr.test.trimestrielle', ['type' => 'quarterly', 'months' => [1, 4, 7, 10], 'day' => 31]);

        expect(dueDates(complianceCompanyOf()))->toBe(['2027-01-31', '2027-04-30', '2027-07-31', '2027-10-31', '2028-01-31']);
    });

    it('utilise les dates explicites d\'une règle', function (): void {
        verifiedDeadlineRule('fr.test.dates', ['type' => 'fixed_dates', 'dates' => ['2026-06-01', '2027-05-20', '2029-05-20']]);

        expect(dueDates(complianceCompanyOf()))->toBe(['2027-05-20']);
    });

    it('place une échéance après la création, seulement si elle est encore à venir', function (): void {
        verifiedDeadlineRule('fr.test.apres-creation', ['type' => 'after_creation', 'days' => 30]);

        expect(dueDates(complianceCompanyOf(null, ['created_on' => '2026-11-01'])))->toBe(['2026-12-01'])
            ->and(dueDates(complianceCompanyOf(complianceUser(), ['created_on' => '2026-03-01'])))->toBe([]);
    });

    it('compte une échéance après la clôture de l\'exercice, fin de mois comprise', function (): void {
        verifiedDeadlineRule('fr.test.approbation', ['type' => 'after_fiscal_year_end', 'offset_months' => 6, 'days' => 0]);
        verifiedDeadlineRule('fr.test.liasse', ['type' => 'after_fiscal_year_end', 'offset_months' => 3, 'days' => 15]);

        $december = complianceCompanyOf();
        $september = complianceCompanyOf(complianceUser(), ['fiscal_year_end' => '09-30', 'created_on' => '2025-01-10']);

        expect(dueDates($december, 'fr.test.approbation'))->toBe(['2027-06-30'])
            ->and(dueDates($december, 'fr.test.liasse'))->toBe(['2027-04-15'])
            ->and(dueDates($september, 'fr.test.approbation'))->toBe(['2027-03-31'])
            ->and(dueDates($september, 'fr.test.liasse'))->toBe(['2027-01-15', '2028-01-15']);
    });

    it('ne génère rien avant la création ni l\'année de création quand la règle l\'exclut', function (): void {
        verifiedDeadlineRule('fr.test.cfe', ['type' => 'yearly', 'month' => 12, 'day' => 15], attributes: [
            'payload' => ['schedule' => ['type' => 'yearly', 'month' => 12, 'day' => 15], 'skip_creation_year' => true, 'tasks' => [], 'expects_document' => false],
        ]);

        expect(dueDates(complianceCompanyOf()))->toBe(['2027-12-15'])
            ->and(dueDates(complianceCompanyOf(complianceUser(), ['created_on' => '2027-06-01'])))->toBe([]);
    });

    it('prend la version de la règle en vigueur à la date de chaque échéance', function (): void {
        verifiedDeadlineRule('fr.test.versions', ['type' => 'yearly', 'month' => 5, 'day' => 3], attributes: ['valid_until' => '2026-12-31']);
        verifiedDeadlineRule('fr.test.versions', ['type' => 'fixed_dates', 'dates' => ['2027-05-20']], attributes: ['valid_from' => '2027-01-01']);

        expect(dueDates(complianceCompanyOf()))->toBe(['2027-05-20']);
    });
});

describe('règles prises en compte', function (): void {
    it('ignore les règles en brouillon, retirées ou d\'un autre pays', function (): void {
        ComplianceRule::factory()->deadline('fr.test.brouillon', ['type' => 'yearly', 'month' => 12, 'day' => 1])->create();
        ComplianceRule::factory()->retired()->deadline('fr.test.retiree', ['type' => 'yearly', 'month' => 12, 'day' => 2])->create();
        verifiedDeadlineRule('be.test.autre', ['type' => 'yearly', 'month' => 12, 'day' => 3], attributes: ['country' => 'BE']);

        expect(dueDates(complianceCompanyOf()))->toBe([]);
    });

    it('applique les conditions de statut, d\'activité, de TVA et d\'option', function (): void {
        verifiedDeadlineRule('fr.test.tous', ['type' => 'yearly', 'month' => 12, 'day' => 1]);
        verifiedDeadlineRule('fr.test.statut', ['type' => 'yearly', 'month' => 12, 'day' => 2], ['legal_forms' => ['fr.legal_form.micro']]);
        verifiedDeadlineRule('fr.test.autre-statut', ['type' => 'yearly', 'month' => 12, 'day' => 3], ['legal_forms' => ['fr.legal_form.sas']]);
        verifiedDeadlineRule('fr.test.vente', ['type' => 'yearly', 'month' => 12, 'day' => 4], ['activity_types' => ['sales']]);
        verifiedDeadlineRule('fr.test.reel', ['type' => 'yearly', 'month' => 12, 'day' => 5], ['vat_regimes' => ['simplified']]);
        verifiedDeadlineRule('fr.test.mensuel', ['type' => 'yearly', 'month' => 12, 'day' => 6], ['options' => ['declaration_frequency' => 'monthly']]);
        verifiedDeadlineRule('fr.test.trimestriel', ['type' => 'yearly', 'month' => 12, 'day' => 7], ['options' => ['declaration_frequency' => 'quarterly']]);
        verifiedDeadlineRule('fr.test.vl', ['type' => 'yearly', 'month' => 12, 'day' => 8], ['options' => ['income_tax_withholding' => false]]);

        $codes = Deadline::query()->where('company_id', complianceCompanyOf()->id)->distinct()->orderBy('rule_code')->pluck('rule_code')->all();

        expect($codes)->toBe(['fr.test.statut', 'fr.test.tous', 'fr.test.trimestriel', 'fr.test.vl']);
    });

    it('crée les tâches de la règle avec leur catégorie de services', function (): void {
        verifiedDeadlineRule('fr.test.taches', ['type' => 'yearly', 'month' => 12, 'day' => 15], [], [
            ['key' => 'preparer', 'title' => ['fr' => 'Préparer', 'en' => null], 'service_category' => null],
            ['key' => 'faire-relire', 'title' => ['fr' => 'Faire relire', 'en' => null], 'service_category' => 'tenue-comptable'],
        ]);
        $company = complianceCompanyOf();

        $tasks = Task::query()->where('company_id', $company->id)->orderBy('deadline_id')->orderBy('position')->get();
        expect($tasks)->toHaveCount(4)
            ->and($tasks->pluck('template_key')->take(2)->all())->toBe(['preparer', 'faire-relire'])
            ->and($tasks[1]->service_category_slug)->toBe('tenue-comptable');
    });
});

describe('recalcul', function (): void {
    it('est idempotent', function (): void {
        verifiedDeadlineRule('fr.test.annuelle', ['type' => 'yearly', 'month' => 12, 'day' => 15]);
        $company = complianceCompanyOf();

        expect(CalendarGenerator::refresh($company))->toBeFalse()
            ->and(dueDates($company))->toBe(['2026-12-15', '2027-12-15']);
    });

    it('ne modifie jamais une échéance faite ou ignorée, et la marque obsolète si sa règle ne s\'applique plus', function (): void {
        $rule = verifiedDeadlineRule('fr.test.annuelle', ['type' => 'yearly', 'month' => 12, 'day' => 15]);
        $company = complianceCompanyOf();
        [$first, $second] = Deadline::query()->where('company_id', $company->id)->orderBy('due_on')->get()->all();
        $first->forceFill(['status' => DeadlineStatus::Done, 'completed_at' => now()])->save();

        $rule->forceFill(['payload' => [...$rule->payload, 'schedule' => ['type' => 'yearly', 'month' => 12, 'day' => 20]]])->save();
        expect(CalendarGenerator::refresh($company))->toBeTrue();

        expect($first->fresh()->due_on->toDateString())->toBe('2026-12-15')
            ->and($first->fresh()->status)->toBe(DeadlineStatus::Done)
            ->and($second->fresh()->due_on->toDateString())->toBe('2027-12-20');

        $rule->forceFill(['status' => 'retired'])->save();
        CalendarGenerator::refresh($company);

        expect($first->fresh()->obsolete_at)->not->toBeNull()
            ->and(Deadline::query()->whereKey($second->id)->exists())->toBeFalse();
    });

    it('garde une échéance passée non faite, et ne touche jamais une échéance personnelle', function (): void {
        $rule = verifiedDeadlineRule('fr.test.annuelle', ['type' => 'yearly', 'month' => 12, 'day' => 15]);
        $company = complianceCompanyOf();
        $custom = new Deadline;
        $custom->forceFill(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'source' => 'custom', 'title' => 'Mon rendez-vous banque', 'due_on' => '2026-12-01'])->save();

        $this->travelTo(Carbon::parse('2026-12-20 10:00:00'));
        $rule->forceFill(['status' => 'retired'])->save();
        CalendarGenerator::refresh($company);

        expect(dueDates($company))->toBe(['2026-12-01', '2026-12-15']);
    });

    it('recalcule les calendriers du pays quand une règle d\'échéance est vérifiée', function (): void {
        Queue::fake();
        $company = complianceCompanyOf();
        $draft = ComplianceRule::factory()->deadline('fr.test.nouvelle', ['type' => 'yearly', 'month' => 12, 'day' => 15])->create();

        asModerator()->postJson("/api/v1/admin/compliance/rules/{$draft->uuid}/verify", ['version' => 1, 'verification_note' => 'Vérifié par le cabinet Exemple le 2026-11-10 sur la page source.'])->assertOk();

        Queue::assertPushed(RefreshCountryCalendars::class, fn ($job): bool => $job->country === 'FR');

        (new RefreshCountryCalendars('FR'))->handle();
        Queue::assertPushed(RefreshCompanyCalendars::class, fn (RefreshCompanyCalendars $job): bool => $job->companyIds === [$company->id]);

        (new RefreshCompanyCalendars([$company->id]))->handle();
        expect(dueDates($company))->toBe(['2026-12-15', '2027-12-15'])
            ->and($company->fresh()->calendar_changed_at)->not->toBeNull();
    });

    it('prolonge les calendriers chaque jour', function (): void {
        verifiedDeadlineRule('fr.test.mensuelle', ['type' => 'monthly', 'day' => 13]);
        $company = complianceCompanyOf();
        $initial = dueDates($company);
        expect(end($initial))->toBe('2028-02-13');

        $this->travelTo(Carbon::parse('2026-12-14 02:00:00'));
        $this->artisan('compliance:extend-calendars')->assertSuccessful();

        $dates = dueDates($company);
        expect(end($dates))->toBe('2028-03-13')
            ->and($dates)->toContain('2026-12-13');
    });
});
