<?php

use App\Support\Calendar\Ics;
use Carbon\CarbonImmutable;

/*
| Briques iCalendar du noyau (App\Support\Calendar\Ics).
*/

it('échappe le texte pour qu\'il ne puisse pas injecter de propriété', function (): void {
    expect(Ics::escape("a;b,c\\d\r\nEND:VEVENT\rx"))->toBe('a\\;b\\,c\\\\d\\nEND:VEVENT\\nx');
});

it('replie les lignes à 75 octets sans couper un caractère multioctet', function (): void {
    $folded = Ics::fold('SUMMARY:'.str_repeat('é', 100));
    $lines = explode("\r\n", $folded);

    expect(count($lines))->toBeGreaterThan(1)
        ->and(collect($lines)->every(fn (string $line): bool => strlen($line) <= 75 && mb_check_encoding($line, 'UTF-8')))->toBeTrue()
        ->and(str_replace("\r\n ", '', $folded))->toBe('SUMMARY:'.str_repeat('é', 100));
});

it('formate les dates en UTC et les journées entières', function (): void {
    $date = CarbonImmutable::parse('2027-03-31 23:30:00', 'Europe/Paris');

    expect(Ics::utcDateTime($date))->toBe('20270331T213000Z')
        ->and(Ics::date(CarbonImmutable::parse('2027-05-20')))->toBe('20270520');
});

it('assemble un calendrier avec ses composants et des lignes terminées par CRLF', function (): void {
    $ics = Ics::document('-//Test//FR', [['BEGIN:VEVENT', 'UID:1', 'END:VEVENT'], ['BEGIN:VEVENT', 'UID:2', 'END:VEVENT']], ['X-WR-CALNAME:Test']);

    expect($ics)->toBe("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//FR\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nX-WR-CALNAME:Test\r\nBEGIN:VEVENT\r\nUID:1\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
});
