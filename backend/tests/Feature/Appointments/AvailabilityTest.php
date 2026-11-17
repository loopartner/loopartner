<?php

use App\Modules\Appointments\Enums\ExceptionKind;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\AvailabilityException;
use App\Modules\Appointments\Models\Calendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Disponibilités des prestataires (module Appointments, décisions 52 et 54) : réglages du
| calendrier, types de rendez-vous, plages hebdomadaires, exceptions et créneaux.
*/

function calendarPayload(array $overrides = []): array
{
    return [
        'timezone' => 'Europe/Paris',
        'is_enabled' => true,
        'public_page_enabled' => false,
        'slot_step_minutes' => 30,
        'buffer_minutes' => 0,
        'min_notice_hours' => 0,
        'horizon_days' => 30,
        'reminder_offsets' => [1440],
        ...$overrides,
    ];
}

function appointmentTypePayload(array $overrides = []): array
{
    return [
        'title' => 'Premier échange',
        'description' => 'Un premier échange pour comprendre votre projet.',
        'duration_minutes' => 30,
        'location_mode' => 'video',
        'location_details' => 'https://visio.exemple.fr/salle',
        ...$overrides,
    ];
}

describe('réglages du calendrier', function (): void {
    it('renvoie les réglages par défaut avant tout enregistrement, puis les enregistre', function (): void {
        $provider = browsingProvider();

        $this->actingAs($provider)->getJson('/api/v1/me/appointments/calendar')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.timezone', 'Europe/Paris')
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.limits.plan', 'free')
            ->assertJsonPath('data.limits.active_types', 2);

        $this->actingAs($provider)->putJson('/api/v1/me/appointments/calendar', calendarPayload(['min_notice_hours' => 12, 'buffer_minutes' => 15]))
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.min_notice_hours', 12)
            ->assertJsonPath('data.effective.reminder_offsets', [1440]);

        expect(appointmentCalendarOf($provider)->min_notice_minutes)->toBe(720);
    });

    it('n\'ouvre les réservations qu\'avec un profil prestataire publié', function (): void {
        $provider = projectOwner('prestataire');

        $this->actingAs($provider)->putJson('/api/v1/me/appointments/calendar', calendarPayload())->assertForbidden();
        $this->actingAs($provider)->putJson('/api/v1/me/appointments/calendar', calendarPayload(['is_enabled' => false]))->assertOk();
    });

    it('réserve la page publique, l\'horizon long et les rappels supplémentaires au Premium', function (array $overrides, string $feature): void {
        $provider = browsingProvider();

        $this->actingAs($provider)->putJson('/api/v1/me/appointments/calendar', calendarPayload($overrides))
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('feature', $feature);

        makePremium($provider);
        $this->actingAs($provider)->putJson('/api/v1/me/appointments/calendar', calendarPayload($overrides))->assertOk();
        expect(securityLog('appointments.premium_required'))->toHaveCount(1);
    })->with([
        'page publique' => [['public_page_enabled' => true], 'public_booking_page'],
        'horizon de 90 jours' => [['horizon_days' => 90], 'booking_horizon'],
        'rappel 2 h avant' => [['reminder_offsets' => [120]], 'reminder_offsets'],
        'deux rappels' => [['reminder_offsets' => [1440, 2880]], 'reminder_offsets'],
    ]);

    it('applique les valeurs gratuites quand le Premium prend fin, sans changer les réglages', function (): void {
        $provider = browsingProvider();
        $grant = makePremium($provider);
        $this->actingAs($provider)->putJson('/api/v1/me/appointments/calendar', calendarPayload(['public_page_enabled' => true, 'horizon_days' => 90, 'reminder_offsets' => [120, 2880]]))->assertOk();

        $grant->forceFill(['revoked_at' => now()])->save();

        $this->actingAs($provider)->getJson('/api/v1/me/appointments/calendar')
            ->assertOk()
            ->assertJsonPath('data.horizon_days', 90)
            ->assertJsonPath('data.public_page_enabled', true)
            ->assertJsonPath('data.effective.public_page_open', false)
            ->assertJsonPath('data.effective.horizon_days', 30)
            ->assertJsonPath('data.effective.reminder_offsets', [1440]);
    });

    it('refuse les valeurs invalides et les champs fixés par la plateforme', function (array $overrides, string $field): void {
        $this->actingAs(browsingProvider())->putJson('/api/v1/me/appointments/calendar', calendarPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'fuseau inconnu' => [['timezone' => 'Europe/Atlantide'], 'timezone'],
        'abréviation' => [['timezone' => 'CET'], 'timezone'],
        'pas inconnu' => [['slot_step_minutes' => 20], 'slot_step_minutes'],
        'marge trop longue' => [['buffer_minutes' => 240], 'buffer_minutes'],
        'prévenance trop longue' => [['min_notice_hours' => 200], 'min_notice_hours'],
        'rappel inconnu' => [['reminder_offsets' => [30]], 'reminder_offsets.0'],
        'aucun rappel' => [['reminder_offsets' => []], 'reminder_offsets'],
        'propriétaire' => [['user_id' => 1], 'user_id'],
    ]);

    it('réserve le calendrier aux prestataires', function (string $role): void {
        $user = projectOwner($role);

        $this->actingAs($user)->getJson('/api/v1/me/appointments/calendar')->assertForbidden();
        $this->actingAs($user)->putJson('/api/v1/me/appointments/calendar', calendarPayload())->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/me/appointment-types', appointmentTypePayload())->assertForbidden();
        $this->actingAs($user)->putJson('/api/v1/me/appointments/availability/rules', ['rules' => []])->assertForbidden();

        expect(Calendar::query()->count())->toBe(0);
    })->with(['particulier', 'entrepreneur', 'redacteur', 'admin']);

    it('exige un email vérifié', function (): void {
        $this->actingAs(projectOwner('prestataire', verified: false))->getJson('/api/v1/me/appointments/calendar')->assertForbidden();
    });
});

describe('types de rendez-vous', function (): void {
    it('crée un type et ne montre son lien de visio qu\'à son prestataire', function (): void {
        $provider = browsingProvider();

        $uuid = $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload())
            ->assertCreated()
            ->assertJsonPath('data.location_details', 'https://visio.exemple.fr/salle')
            ->assertJsonPath('data.bookable', true)
            ->assertJsonPath('data.visibility', 'relation')
            ->json('data.uuid');

        $this->actingAs($provider)->getJson('/api/v1/me/appointment-types')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs(browsingProvider())->getJson("/api/v1/me/appointment-types/{$uuid}")->assertNotFound();
        $this->actingAs(browsingProvider())->patchJson("/api/v1/me/appointment-types/{$uuid}", ['title' => 'Piraté'])->assertNotFound();
        $this->actingAs(browsingProvider())->deleteJson("/api/v1/me/appointment-types/{$uuid}")->assertNotFound();

        expect(AppointmentType::query()->sole()->title)->toBe('Premier échange');
    });

    it('chiffre le détail du lieu en base', function (): void {
        $provider = browsingProvider();
        $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload())->assertCreated();

        expect((string) DB::table('appointment_types')->value('location_details'))->not->toContain('visio.exemple.fr');
    });

    it('exige le détail du lieu selon le mode, et l\'efface quand le client le donne', function (): void {
        $provider = browsingProvider();

        $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload(['location_details' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('location_details');
        $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload(['location_mode' => 'provider_place', 'location_details' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('location_details');
        $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload(['location_details' => 'javascript:alert(1)']))
            ->assertUnprocessable()->assertJsonValidationErrors('location_details');
        $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload(['location_details' => 'http://visio.exemple.fr']))
            ->assertUnprocessable()->assertJsonValidationErrors('location_details');

        $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload(['location_mode' => 'phone']))
            ->assertCreated()
            ->assertJsonPath('data.location_details', null);
    });

    it('refuse le HTML, les durées invalides et les champs fixés par la plateforme', function (array $overrides, string $field): void {
        $this->actingAs(browsingProvider())->postJson('/api/v1/me/appointment-types', appointmentTypePayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'HTML' => [['title' => '<b>Échange</b>'], 'title'],
        'durée trop courte' => [['duration_minutes' => 10], 'duration_minutes'],
        'durée hors pas' => [['duration_minutes' => 32], 'duration_minutes'],
        'mode inconnu' => [['location_mode' => 'teleport'], 'location_mode'],
        'uuid' => [['uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'], 'uuid'],
        'calendrier' => [['calendar_id' => 1], 'calendar_id'],
        'modération' => [['moderation_restricted_at' => '2027-01-01T00:00:00Z'], 'moderation_restricted_at'],
    ]);

    it('limite les types actifs à l\'offre gratuite, puis au Premium', function (): void {
        $provider = browsingProvider();
        $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload())->assertCreated();
        $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload())->assertCreated();

        $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload())
            ->assertForbidden()
            ->assertJsonPath('feature', 'appointment_types');

        $inactive = $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload(['is_active' => false]))
            ->assertCreated()
            ->json('data.uuid');
        $this->actingAs($provider)->patchJson("/api/v1/me/appointment-types/{$inactive}", ['is_active' => true])->assertForbidden();

        makePremium($provider);
        $this->actingAs($provider)->patchJson("/api/v1/me/appointment-types/{$inactive}", ['is_active' => true])->assertOk();
    });

    it('garde réservables les premiers types seulement à la fin du Premium', function (): void {
        $provider = browsingProvider();
        $grant = makePremium($provider);

        foreach ([3, 1, 2] as $position) {
            $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload(['title' => "Type {$position}", 'position' => $position]))->assertCreated();
        }

        $grant->forceFill(['revoked_at' => now()])->save();

        $types = collect($this->actingAs($provider)->getJson('/api/v1/me/appointment-types')->assertOk()->json('data'));
        expect($types->pluck('title')->all())->toBe(['Type 1', 'Type 2', 'Type 3'])
            ->and($types->pluck('bookable')->all())->toBe([true, true, false]);
    });

    it('modifie partiellement et supprime un type', function (): void {
        $provider = browsingProvider();
        $uuid = $this->actingAs($provider)->postJson('/api/v1/me/appointment-types', appointmentTypePayload())->json('data.uuid');

        $this->actingAs($provider)->patchJson("/api/v1/me/appointment-types/{$uuid}", ['duration_minutes' => 45, 'auto_confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 45)
            ->assertJsonPath('data.auto_confirm', true)
            ->assertJsonPath('data.title', 'Premier échange');

        $this->actingAs($provider)->deleteJson("/api/v1/me/appointment-types/{$uuid}")->assertNoContent();
        expect(AppointmentType::query()->count())->toBe(0);
    });
});

describe('plages hebdomadaires et exceptions', function (): void {
    it('remplace les plages en bloc', function (): void {
        $provider = browsingProvider();

        $this->actingAs($provider)->putJson('/api/v1/me/appointments/availability/rules', ['rules' => [
            ['weekday' => 1, 'start' => '14:00', 'end' => '18:00'],
            ['weekday' => 1, 'start' => '09:00', 'end' => '12:00'],
            ['weekday' => 5, 'start' => '20:00', 'end' => '24:00'],
        ]])->assertOk()->assertJsonPath('data', [
            ['weekday' => 1, 'start' => '09:00', 'end' => '12:00'],
            ['weekday' => 1, 'start' => '14:00', 'end' => '18:00'],
            ['weekday' => 5, 'start' => '20:00', 'end' => '24:00'],
        ]);

        $this->actingAs($provider)->putJson('/api/v1/me/appointments/availability/rules', ['rules' => []])->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuse les plages incohérentes', function (array $rule, string $field): void {
        $this->actingAs(browsingProvider())->putJson('/api/v1/me/appointments/availability/rules', ['rules' => [
            ['weekday' => 2, 'start' => '09:00', 'end' => '12:00'],
            $rule,
        ]])->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'chevauchement' => [['weekday' => 2, 'start' => '11:00', 'end' => '13:00'], 'rules.1.start'],
        'fin avant le début' => [['weekday' => 3, 'start' => '12:00', 'end' => '09:00'], 'rules.1.end'],
        'jour inconnu' => [['weekday' => 8, 'start' => '09:00', 'end' => '10:00'], 'rules.1.weekday'],
        'heure hors pas' => [['weekday' => 3, 'start' => '09:07', 'end' => '10:00'], 'rules.1.start'],
        'clé inconnue' => [['weekday' => 3, 'start' => '09:00', 'end' => '10:00', 'calendar_id' => 1], 'rules.1'],
    ]);

    it('limite le nombre de plages à l\'offre gratuite', function (): void {
        $provider = browsingProvider();
        $rules = array_map(fn (int $hour): array => ['weekday' => 1, 'start' => sprintf('%02d:00', $hour), 'end' => sprintf('%02d:30', $hour)], range(8, 18));

        $this->actingAs($provider)->putJson('/api/v1/me/appointments/availability/rules', ['rules' => $rules])
            ->assertForbidden()
            ->assertJsonPath('feature', 'weekly_ranges');

        makePremium($provider);
        $this->actingAs($provider)->putJson('/api/v1/me/appointments/availability/rules', ['rules' => $rules])->assertOk()->assertJsonCount(11, 'data');
    });

    it('ajoute, liste et supprime des exceptions à venir', function (): void {
        $this->travelTo(CarbonImmutable::parse('2027-03-01 10:00:00'));
        $provider = browsingProvider();

        $closed = $this->actingAs($provider)->postJson('/api/v1/me/appointments/availability/exceptions', ['date' => '2027-03-10', 'kind' => 'closed'])
            ->assertCreated()
            ->assertJsonPath('data.start', null)
            ->json('data.uuid');
        $this->actingAs($provider)->postJson('/api/v1/me/appointments/availability/exceptions', ['date' => '2027-03-13', 'kind' => 'open', 'start' => '10:00', 'end' => '12:00'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'open');

        $this->actingAs($provider)->getJson('/api/v1/me/appointments/availability/exceptions')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs(browsingProvider())->deleteJson("/api/v1/me/appointments/availability/exceptions/{$closed}")->assertNotFound();
        $this->actingAs($provider)->deleteJson("/api/v1/me/appointments/availability/exceptions/{$closed}")->assertNoContent();

        expect(AvailabilityException::query()->sole()->kind)->toBe(ExceptionKind::Open);
    });

    it('refuse une exception passée, trop lointaine ou incomplète', function (array $payload, string $field): void {
        $this->travelTo(CarbonImmutable::parse('2027-03-01 10:00:00'));

        $this->actingAs(browsingProvider())->postJson('/api/v1/me/appointments/availability/exceptions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'passée' => [['date' => '2027-02-28', 'kind' => 'closed'], 'date'],
        'trop lointaine' => [['date' => '2028-06-01', 'kind' => 'closed'], 'date'],
        'ouverture sans heures' => [['date' => '2027-03-10', 'kind' => 'open'], 'start'],
        'début seul' => [['date' => '2027-03-10', 'kind' => 'closed', 'start' => '10:00'], 'start'],
        'fin avant le début' => [['date' => '2027-03-10', 'kind' => 'closed', 'start' => '12:00', 'end' => '10:00'], 'end'],
    ]);
});

describe('créneaux', function (): void {
    beforeEach(function (): void {
        // Lundi 22 mars 2027, 08:00 à Paris (07:00 UTC).
        $this->travelTo(CarbonImmutable::parse('2027-03-22 07:00:00'));
    });

    it('découpe les plages en créneaux de la durée du type, au pas du calendrier', function (): void {
        $provider = appointmentProvider(['slot_step_minutes' => 30]);
        weeklyAvailability($provider, [[1, 9 * 60, 11 * 60]]);
        $type = appointmentTypeOf($provider, ['duration_minutes' => 60]);

        $this->actingAs($provider)->getJson("/api/v1/me/appointments/slots?type={$type->uuid}&from=2027-03-22&to=2027-03-22")
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'Europe/Paris')
            ->assertJsonPath('data', [
                ['starts_at' => '2027-03-22T08:00:00Z', 'ends_at' => '2027-03-22T09:00:00Z', 'local' => ['starts_at' => '2027-03-22T09:00', 'ends_at' => '2027-03-22T10:00', 'offset' => '+01:00']],
                ['starts_at' => '2027-03-22T08:30:00Z', 'ends_at' => '2027-03-22T09:30:00Z', 'local' => ['starts_at' => '2027-03-22T09:30', 'ends_at' => '2027-03-22T10:30', 'offset' => '+01:00']],
                ['starts_at' => '2027-03-22T09:00:00Z', 'ends_at' => '2027-03-22T10:00:00Z', 'local' => ['starts_at' => '2027-03-22T10:00', 'ends_at' => '2027-03-22T11:00', 'offset' => '+01:00']],
            ]);
    });

    it('applique les fermetures et les ouvertures ponctuelles', function (): void {
        $provider = appointmentProvider();
        weeklyAvailability($provider);
        $type = appointmentTypeOf($provider, ['duration_minutes' => 60]);
        $calendar = appointmentCalendarOf($provider);

        foreach ([
            ['date' => '2027-03-23', 'kind' => ExceptionKind::Closed, 'start_minute' => null, 'end_minute' => null],
            ['date' => '2027-03-24', 'kind' => ExceptionKind::Closed, 'start_minute' => 10 * 60, 'end_minute' => 11 * 60],
            ['date' => '2027-03-25', 'kind' => ExceptionKind::Open, 'start_minute' => 14 * 60, 'end_minute' => 15 * 60],
        ] as $exception) {
            (new AvailabilityException)->forceFill(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id, ...$exception])->save();
        }

        $starts = fn (string $day): array => collect($this->actingAs($provider)->getJson("/api/v1/me/appointments/slots?type={$type->uuid}&from={$day}&to={$day}")->json('data'))->pluck('local.starts_at')->all();

        expect($starts('2027-03-23'))->toBe([])
            ->and($starts('2027-03-24'))->toBe(['2027-03-24T09:00', '2027-03-24T11:00'])
            ->and($starts('2027-03-25'))->toBe(['2027-03-25T09:00', '2027-03-25T09:30', '2027-03-25T10:00', '2027-03-25T10:30', '2027-03-25T11:00', '2027-03-25T14:00']);
    });

    it('respecte le délai de prévenance et l\'horizon', function (): void {
        $provider = appointmentProvider(['min_notice_minutes' => 3 * 60, 'horizon_days' => 2]);
        weeklyAvailability($provider);
        $type = appointmentTypeOf($provider, ['duration_minutes' => 60]);

        $starts = collect($this->actingAs($provider)->getJson("/api/v1/me/appointments/slots?type={$type->uuid}&from=2027-03-22&to=2027-03-26")->json('data'))->pluck('local.starts_at');

        // Maintenant : lundi 08:00 ; prévenance de 3 heures ; horizon : mercredi 08:00.
        expect($starts->first())->toBe('2027-03-22T11:00')
            ->and($starts->last())->toBe('2027-03-23T11:00');
    });

    it('limite la plage demandée à 31 jours', function (): void {
        $provider = appointmentProvider();
        $type = appointmentTypeOf($provider);

        $this->actingAs($provider)->getJson("/api/v1/me/appointments/slots?type={$type->uuid}&from=2027-03-22&to=2027-05-01")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->actingAs($provider)->getJson('/api/v1/me/appointments/slots?type='.appointmentTypeOf(appointmentProvider())->uuid)->assertNotFound();
    });

    it('ne propose aucun créneau dans l\'heure qui n\'existe pas au passage à l\'heure d\'été', function (): void {
        $provider = appointmentProvider();
        weeklyAvailability($provider, [[7, 60, 4 * 60]]);
        $type = appointmentTypeOf($provider, ['duration_minutes' => 30]);

        $slots = collect($this->actingAs($provider)->getJson("/api/v1/me/appointments/slots?type={$type->uuid}&from=2027-03-28&to=2027-03-28")->assertOk()->json('data'));

        expect($slots->pluck('local.starts_at')->all())->toBe(['2027-03-28T01:00', '2027-03-28T01:30', '2027-03-28T03:00', '2027-03-28T03:30'])
            ->and($slots->pluck('starts_at')->all())->toBe(['2027-03-28T00:00:00Z', '2027-03-28T00:30:00Z', '2027-03-28T01:00:00Z', '2027-03-28T01:30:00Z'])
            ->and($slots[1]['local']['ends_at'])->toBe('2027-03-28T03:00');
    });

    it('propose deux fois les créneaux de l\'heure répétée au passage à l\'heure d\'hiver, avec leur décalage', function (): void {
        $this->travelTo(CarbonImmutable::parse('2027-10-25 07:00:00'));
        $provider = appointmentProvider();
        weeklyAvailability($provider, [[7, 60, 4 * 60]]);
        $type = appointmentTypeOf($provider, ['duration_minutes' => 30]);

        $slots = collect($this->actingAs($provider)->getJson("/api/v1/me/appointments/slots?type={$type->uuid}&from=2027-10-31&to=2027-10-31")->assertOk()->json('data'));

        expect($slots)->toHaveCount(8)
            ->and($slots->map(fn (array $slot): string => $slot['local']['starts_at'].$slot['local']['offset'])->all())->toBe([
                '2027-10-31T01:00+02:00', '2027-10-31T01:30+02:00', '2027-10-31T02:00+02:00', '2027-10-31T02:30+02:00',
                '2027-10-31T02:00+01:00', '2027-10-31T02:30+01:00', '2027-10-31T03:00+01:00', '2027-10-31T03:30+01:00',
            ])
            ->and($slots->pluck('starts_at')->unique())->toHaveCount(8);
    });
});
