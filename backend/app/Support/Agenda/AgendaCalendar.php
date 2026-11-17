<?php

namespace App\Support\Agenda;

use App\Support\Calendar\Ics;

/**
 * Abonnement unique à l'agenda (iCalendar) : **titres et dates seulement** (critère du lot 4).
 * Ni lieu, ni lien, ni note, ni nom : l'URL peut être partagée par erreur. Seul un texte fixe
 * de la source (avertissement du suivi administratif) peut figurer en description.
 */
final class AgendaCalendar
{
    /**
     * @param  list<AgendaItem>  $items
     */
    public static function render(array $items, string $locale): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $components = [];

        foreach ($items as $item) {
            $component = [
                'BEGIN:VEVENT',
                'UID:'.Ics::escape($item->source.'-'.$item->ref.'@'.$host),
                'DTSTAMP:'.Ics::utcDateTime(now()),
                ...($item->allDay
                    ? ['DTSTART;VALUE=DATE:'.Ics::date($item->startsAt), 'DTEND;VALUE=DATE:'.Ics::date($item->endsAt), 'TRANSP:TRANSPARENT']
                    : ['DTSTART:'.Ics::utcDateTime($item->startsAt), 'DTEND:'.Ics::utcDateTime($item->endsAt)]),
                'SUMMARY:'.Ics::escape($item->title),
                ...($item->feedNote === null ? [] : ['DESCRIPTION:'.Ics::escape($item->feedNote)]),
                'END:VEVENT',
            ];

            $components[] = $component;
        }

        return Ics::document('-//Loopartner//Agenda//FR', $components, [
            'X-WR-CALNAME:'.Ics::escape($locale === 'en' ? 'Loopartner – my calendar' : 'Loopartner – mon agenda'),
        ]);
    }
}
