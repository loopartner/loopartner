<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\Deadline;
use App\Support\Calendar\Ics;
use Carbon\CarbonImmutable;

/**
 * Calendrier d'abonnement d'un utilisateur (iCalendar, briques du noyau App\Support\Calendar\Ics).
 *
 * Contenu volontairement minimal, car l'URL peut être partagée par erreur (calendrier public) :
 * titres et dates des échéances, en journée entière. Jamais de montant, de note, de numéro
 * d'entreprise ni de lien de connexion. Le nom de l'entreprise n'apparaît que si l'utilisateur
 * en suit plusieurs. Entreprises actives seulement (pas celles verrouillées après la fin du
 * premium) ; échéances ignorées exclues ; du mois écoulé à la fin de la fenêtre du calendrier.
 */
final class ComplianceCalendar
{
    /**
     * @param  iterable<Company>  $companies
     */
    public static function render(iterable $companies, int $companyCount, string $locale): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $from = CarbonImmutable::now()->subDays(31)->toDateString();
        $components = [];

        foreach ($companies as $company) {
            $deadlines = Deadline::query()
                ->where('company_id', $company->id)
                ->where('status', '!=', DeadlineStatus::Skipped->value)
                ->whereDate('due_on', '>=', $from)
                ->with('rule')
                ->orderBy('due_on')
                ->get();

            foreach ($deadlines as $deadline) {
                $title = DeadlineTitle::of($deadline, $locale);

                if ($companyCount > 1) {
                    $title = $company->name.' : '.$title;
                }

                if ($deadline->status === DeadlineStatus::Done) {
                    $title = '✓ '.$title;
                }

                $day = CarbonImmutable::parse($deadline->due_on->toDateString());

                $components[] = [
                    'BEGIN:VEVENT',
                    'UID:'.Ics::escape($deadline->uuid.'@'.$host),
                    'DTSTAMP:'.Ics::utcDateTime(now()),
                    'DTSTART;VALUE=DATE:'.Ics::date($day),
                    'DTEND;VALUE=DATE:'.Ics::date($day->addDay()),
                    'SUMMARY:'.Ics::escape($title),
                    'DESCRIPTION:'.Ics::escape(Disclaimer::text($locale)),
                    'TRANSP:TRANSPARENT',
                    'END:VEVENT',
                ];
            }
        }

        return Ics::document('-//Loopartner//Suivi administratif//FR', $components, [
            'X-WR-CALNAME:'.Ics::escape($locale === 'en' ? 'Loopartner – administrative deadlines' : 'Loopartner – échéances administratives'),
        ]);
    }
}
