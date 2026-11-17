<?php

namespace App\Modules\Compliance\Support;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\Deadline;
use App\Support\Agenda\AgendaItem;
use App\Support\Agenda\AgendaSource;
use Carbon\CarbonImmutable;

/**
 * Échéances du suivi administratif dans l'agenda unifié (lot 4), mêmes règles que l'abonnement
 * du module (ComplianceCalendar, inchangé) : entreprises actives seulement, échéances ignorées
 * ou devenues obsolètes exclues, journée entière, titre de l'échéance (précédé du nom de
 * l'entreprise si l'utilisateur en suit plusieurs), « ✓ » pour une échéance faite. Jamais de
 * montant, de note ni de numéro d'entreprise. Toute réponse qui en contient porte
 * l'avertissement du module (disclaimer).
 */
class ComplianceAgendaSource implements AgendaSource
{
    public function __construct(private Entitlements $entitlements) {}

    public function items(User $user, CarbonImmutable $from, CarbonImmutable $to, string $locale, bool $forFeed): array
    {
        if (! $user->hasAnyRole([AccountType::Prestataire->value, AccountType::Entrepreneur->value])) {
            return [];
        }

        $companies = Company::query()->where('user_id', $user->id)->orderBy('id')->get();
        $active = $companies->reject(fn (Company $company): bool => $this->entitlements->isLocked($company));
        $note = Disclaimer::text($locale);
        $items = [];

        foreach ($active as $company) {
            $deadlines = Deadline::query()
                ->where('company_id', $company->id)
                ->where('status', '!=', DeadlineStatus::Skipped->value)
                ->whereNull('obsolete_at')
                ->whereDate('due_on', '>=', $from->toDateString())
                ->whereDate('due_on', '<', $to->toDateString())
                ->with('rule')
                ->orderBy('due_on')
                ->limit(500)
                ->get();

            foreach ($deadlines as $deadline) {
                $title = DeadlineTitle::of($deadline, $locale);
                $title = $companies->count() > 1 ? $company->name.' : '.$title : $title;
                $day = CarbonImmutable::parse($deadline->due_on->toDateString(), 'UTC');

                $items[] = new AgendaItem(
                    source: 'compliance',
                    kind: 'deadline',
                    ref: $deadline->uuid,
                    title: $deadline->status === DeadlineStatus::Done ? '✓ '.$title : $title,
                    startsAt: $day,
                    endsAt: $day->addDay(),
                    allDay: true,
                    timezone: null,
                    status: $deadline->status->value,
                    feedNote: $forFeed ? $note : null,
                );
            }
        }

        return $items;
    }

    public function disclaimer(string $locale): ?array
    {
        return Disclaimer::for($locale);
    }
}
