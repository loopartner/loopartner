<?php

use App\Support\Time\LocalTime;

/*
| Heure locale et UTC (App\Support\Time\LocalTime). En France, l'heure d'été commence le
| dernier dimanche de mars à 1 h UTC (02:00 devient 03:00) et finit le dernier dimanche
| d'octobre à 1 h UTC (03:00 redevient 02:00) : 2026-03-29, 2026-10-25, 2027-03-28 et
| 2027-10-31.
*/

it('convertit une heure locale ordinaire en UTC, en hiver et en été', function (): void {
    expect(LocalTime::instant('2027-01-15', 9 * 60, 'Europe/Paris')->toIso8601ZuluString())->toBe('2027-01-15T08:00:00Z')
        ->and(LocalTime::instant('2027-07-15', 9 * 60, 'Europe/Paris')->toIso8601ZuluString())->toBe('2027-07-15T07:00:00Z')
        ->and(LocalTime::instant('2027-07-15', LocalTime::MINUTES_PER_DAY, 'Europe/Paris')->toIso8601ZuluString())->toBe('2027-07-15T22:00:00Z');
});

it('avance une heure inexistante (passage à l\'heure d\'été) à l\'instant du changement', function (string $day): void {
    $instant = LocalTime::instant($day, 2 * 60 + 30, 'Europe/Paris');

    expect($instant->format('H:i'))->toBe('01:00')
        ->and(LocalTime::present($instant, 'Europe/Paris'))->toBe(['date_time' => $day.'T03:00', 'offset' => '+02:00']);
})->with(['2026-03-29', '2027-03-28']);

it('résout une heure ambiguë (passage à l\'heure d\'hiver) en première occurrence pour un début et en dernière pour une fin', function (string $day): void {
    $start = LocalTime::instant($day, 2 * 60 + 30, 'Europe/Paris', LocalTime::EDGE_START);
    $end = LocalTime::instant($day, 2 * 60 + 30, 'Europe/Paris', LocalTime::EDGE_END);

    expect($start->format('H:i'))->toBe('00:30')
        ->and($end->format('H:i'))->toBe('01:30')
        ->and(LocalTime::present($start, 'Europe/Paris')['offset'])->toBe('+02:00')
        ->and(LocalTime::present($end, 'Europe/Paris')['offset'])->toBe('+01:00');
})->with(['2026-10-25', '2027-10-31']);

it('donne une plage de 01:00 à 04:00 de deux heures réelles au printemps et de quatre à l\'automne', function (): void {
    $spring = LocalTime::instant('2027-03-28', 60, 'Europe/Paris')
        ->diffInMinutes(LocalTime::instant('2027-03-28', 240, 'Europe/Paris', LocalTime::EDGE_END));
    $autumn = LocalTime::instant('2027-10-31', 60, 'Europe/Paris')
        ->diffInMinutes(LocalTime::instant('2027-10-31', 240, 'Europe/Paris', LocalTime::EDGE_END));

    expect((int) $spring)->toBe(120)->and((int) $autumn)->toBe(240);
});

it('n\'accepte que les fuseaux IANA', function (mixed $timezone, bool $valid): void {
    expect(LocalTime::isValidTimezone($timezone))->toBe($valid);
})->with([
    ['Europe/Paris', true],
    ['America/Toronto', true],
    ['America/Montreal', false],
    ['UTC', true],
    ['CET', false],
    ['+01:00', false],
    ['Europe/Atlantide', false],
    ['', false],
    [null, false],
]);
