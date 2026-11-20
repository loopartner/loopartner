<?php

use App\Modules\Compliance\Actions\ManageDeadline;
use App\Modules\Compliance\Models\CalendarFeed;
use App\Modules\Compliance\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
| Abonnement au calendrier (URL privée à jeton) et export complet des données.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
    verifiedLegalForm();
    verifiedDeadlineRule('fr.test.cfe', ['type' => 'yearly', 'month' => 12, 'day' => 15]);
});

function feedPath(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

function issueFeed(Company $company): string
{
    return test()->actingAs($company->user)->postJson('/api/v1/me/compliance/calendar-feed')->assertCreated()->json('data.url');
}

describe('abonnement au calendrier', function (): void {
    it('crée une URL privée montrée une seule fois, dont seul le sélecteur et une empreinte sont stockés', function (): void {
        $company = complianceCompanyOf();
        $url = issueFeed($company);
        $token = substr(basename(feedPath($url)), 0, -4);

        expect($url)->toStartWith(rtrim((string) config('app.url'), '/').'/api/v1/compliance/calendar/')
            ->and(strlen($token))->toBe(56)
            ->and(json_encode(DB::table('compliance_calendar_feeds')->get()))->not->toContain(substr($token, 16))
            ->and(securityLog('compliance.calendar_feed_created'))->toHaveCount(1)
            ->and(json_encode(array_map(fn ($record) => $record->context, securityLog())))->not->toContain(substr($token, 16));

        $this->actingAs($company->user)->getJson('/api/v1/me/compliance/calendar-feed')
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonMissingPath('data.url');
    });

    it('verrouille la ligne users et recommence si une autre requête crée l\'abonnement au même moment', function (): void {
        $company = complianceCompanyOf();

        simulateConcurrentRequestAfterQuery('from compliance_calendar_feeds where user_id', function () use ($company): void {
            DB::table('compliance_calendar_feeds')->insert(['user_id' => $company->user_id, 'selector' => str_repeat('a', 16), 'verifier_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        });
        $queries = recordQueries();

        $url = issueFeed($company);

        $this->get(feedPath($url))->assertOk();
        expect(CalendarFeed::query()->count())->toBe(1);

        $first = collect($queries)->first(fn (array $query): bool => $query['depth'] > 0 && preg_match('/\b(users|compliance_calendar_feeds)\b/', str_replace(['"', '`'], '', $query['sql'])) === 1);
        expect($first['sql'])->toContain('users')->not->toContain('compliance_calendar_feeds');

        if (DB::getDriverName() !== 'sqlite') {
            expect($first['sql'])->toContain('for update');
        }
    });

    it('sert les titres et dates des échéances, sans montant ni note, sans être connecté', function (): void {
        $company = complianceCompanyOf();
        app(ManageDeadline::class)->create($company, ['title' => 'Rendez-vous ; banque', 'due_on' => '2026-11-20', 'notes' => 'Note privée 4 120,50']);
        $url = issueFeed($company);
        $this->app['auth']->forgetGuards();

        $ics = (string) $this->get(feedPath($url))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->getContent();

        expect($ics)->toContain('SUMMARY:Rendez-vous \\; banque')
            ->toContain('DTSTART;VALUE=DATE:20261120')
            ->toContain('DTEND;VALUE=DATE:20261121')
            ->toContain('SUMMARY:Échéance fr.test.cfe')
            ->toContain('DTSTART;VALUE=DATE:20261215')
            ->not->toContain('Note privée')
            ->not->toContain('4 120')
            ->not->toContain('Atelier Camille')
            ->not->toContain('12345678900012');
    });

    it('invalide l\'ancienne URL quand elle est remplacée, et toute URL après révocation (404)', function (): void {
        $company = complianceCompanyOf();
        $first = issueFeed($company);
        $second = issueFeed($company);

        $this->get(feedPath($first))->assertNotFound();
        $this->get(feedPath($second))->assertOk();
        expect(securityLog('compliance.calendar_feed_regenerated'))->toHaveCount(1);

        $this->actingAs($company->user)->deleteJson('/api/v1/me/compliance/calendar-feed')->assertNoContent();
        $this->get(feedPath($second))->assertNotFound();
        $this->actingAs($company->user)->deleteJson('/api/v1/me/compliance/calendar-feed')->assertNotFound();
    });

    it('répond 404 à un jeton inconnu ou altéré, et ne le journalise qu\'une fois par heure et par IP, sans le jeton', function (): void {
        $company = complianceCompanyOf();
        $url = issueFeed($company);
        $altered = substr(feedPath($url), 0, -5).(str_ends_with(feedPath($url), 'a.ics') ? 'b' : 'a').'.ics';

        $this->get($altered)->assertNotFound();
        $this->get('/api/v1/compliance/calendar/'.str_repeat('Z', 56).'.ics')->assertNotFound();
        $this->get('/api/v1/compliance/calendar/trop-court.ics')->assertNotFound();

        expect(securityLog('compliance.calendar_feed_invalid'))->toHaveCount(1)
            ->and(json_encode(array_map(fn ($record) => $record->context, securityLog())))->not->toContain(str_repeat('Z', 56));
    });

    it('limite la lecture de l\'abonnement (429)', function (): void {
        config(['security.rate_limits.compliance_feed.per_hour_per_feed' => 2]);
        $company = complianceCompanyOf();
        $path = feedPath(issueFeed($company));

        $this->get($path)->assertOk();
        $this->get($path)->assertOk();
        $this->get($path)->assertTooManyRequests();
    });

    it('limite la création d\'URL (429)', function (): void {
        config(['security.rate_limits.compliance_feed_manage.per_day' => 1]);
        $company = complianceCompanyOf();
        issueFeed($company);

        $this->actingAs($company->user)->postJson('/api/v1/me/compliance/calendar-feed')->assertTooManyRequests();
    });

    it('exclut les entreprises verrouillées et préfixe le nom quand il y a plusieurs entreprises', function (): void {
        $user = complianceUser();
        $grant = makePremium($user);
        $first = complianceCompanyOf($user);
        complianceCompanyOf($user, ['name' => 'Seconde']);
        $path = feedPath(issueFeed($first));

        expect((string) $this->get($path)->getContent())->toContain('SUMMARY:Atelier Camille : Échéance')->toContain('SUMMARY:Seconde : Échéance');

        $grant->forceFill(['ends_at' => now()->subMinute()])->save();
        expect((string) $this->get($path)->getContent())->toContain('Atelier Camille')->not->toContain('Seconde');
    });

    it('cesse de fonctionner si l\'utilisateur perd son rôle', function (): void {
        $company = complianceCompanyOf();
        $path = feedPath(issueFeed($company));
        $company->user->syncRoles(['particulier']);

        $this->get($path)->assertNotFound();
    });

    it('réserve la gestion de l\'abonnement aux prestataires et entrepreneurs', function (string $role): void {
        $this->actingAs(complianceUser($role))->postJson('/api/v1/me/compliance/calendar-feed')->assertForbidden();
        expect(CalendarFeed::query()->count())->toBe(0);
    })->with(['particulier', 'redacteur', 'admin']);
});

describe('export', function (): void {
    it('exporte toutes ses données, sans identifiant interne ni donnée d\'un autre utilisateur', function (): void {
        $user = complianceUser();
        $company = complianceCompanyOf($user);
        app(ManageDeadline::class)->create($company, ['title' => 'Rendez-vous banque', 'due_on' => '2026-11-20', 'notes' => 'Ma note']);
        $this->actingAs($user)->putJson("/api/v1/me/compliance/companies/{$company->uuid}/revenue/2026-10", ['lines' => ['services_commercial' => 412_050]])->assertOk();
        complianceCompanyOf();

        $response = $this->actingAs($user)->get('/api/v1/me/compliance/export')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="loopartner-suivi-administratif-20261113.json"');
        $data = $response->json();

        expect($data['format'])->toBe('loopartner.compliance.export')
            ->and($data['companies'])->toHaveCount(1)
            ->and($data['companies'][0]['registration_number'])->toBe('12345678900012')
            ->and($data['companies'][0]['revenue'])->toBe([['period' => '2026-10', 'activity_type' => 'services_commercial', 'amount' => 412_050]])
            ->and(collect($data['companies'][0]['deadlines'])->pluck('title')->all())->toContain('Rendez-vous banque')
            ->and(collect($data['companies'][0]['deadlines'])->pluck('notes')->all())->toContain('Ma note')
            ->and($data['companies'][0]['documents'])->toBe([])
            ->and($data['disclaimer']['version'])->toBe(1)
            ->and(securityLog('compliance.export_downloaded'))->toHaveCount(1);

        expectNoPrivateFields($data);
    });

    it('limite les exports (429)', function (): void {
        config(['security.rate_limits.compliance_exports.per_hour' => 1]);
        $user = complianceUser();

        $this->actingAs($user)->getJson('/api/v1/me/compliance/export')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/me/compliance/export')->assertTooManyRequests();
    });

    it('réserve l\'export aux prestataires et entrepreneurs', function (): void {
        $this->actingAs(complianceUser('particulier'))->getJson('/api/v1/me/compliance/export')->assertForbidden();
    });
});
