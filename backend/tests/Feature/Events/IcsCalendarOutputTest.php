<?php

use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\IcsCalendar;
use Illuminate\Support\Carbon;

/*
| Non-régression : le fichier .ics d'un événement est construit avec les briques du noyau
| (App\Support\Calendar\Ics) et doit rester identique, octet pour octet, à la sortie produite
| avant ce déplacement (fichiers de référence dans tests/Fixtures/ics).
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    config(['app.url' => 'https://api.loopartner.test', 'app.frontend_url' => 'https://www.loopartner.test']);
});

function icsReferenceEvent(): Event
{
    return Event::factory()->published()->create([
        'slug' => 'atelier-compta-tva-abc123',
        'version' => 3,
        'format' => 'hybrid',
        'online_url' => 'https://visio.exemple.fr/salle',
        'address_visibility' => 'registrants',
        'title' => 'Atelier ; compta, TVA',
        'description' => "Programme :\nBEGIN:VEVENT\\ fin".str_repeat(' long texte é', 12),
    ]);
}

it('produit le même fichier public qu\'avant le déplacement dans le noyau', function (): void {
    expect(IcsCalendar::render(icsReferenceEvent(), false))
        ->toBe(file_get_contents(base_path('tests/Fixtures/ics/event-public.ics')));
});

it('produit le même fichier d\'inscrit qu\'avant le déplacement dans le noyau', function (): void {
    expect(IcsCalendar::render(icsReferenceEvent(), true))
        ->toBe(file_get_contents(base_path('tests/Fixtures/ics/event-registrant.ics')));
});
