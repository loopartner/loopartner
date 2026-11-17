<?php

namespace App\Support\Agenda;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Contrat d'une source de l'agenda unifié (lot 4). Chaque module fournit un adaptateur pour
 * ses éléments datés (rendez-vous, événements suivis, échéances), déclaré dans
 * config/agenda.php (sources). Le noyau ne connaît que ce contrat : il n'importe jamais un
 * module, et chaque module ne lit que ses propres tables.
 */
interface AgendaSource
{
    /**
     * Éléments de l'utilisateur qui chevauchent [from, to[, triés ou non.
     *
     * @param  bool  $forFeed  abonnement (.ics) : seulement les éléments confirmés ou publiés
     *                         (ni demande en attente, ni annulé)
     * @return list<AgendaItem>
     */
    public function items(User $user, CarbonImmutable $from, CarbonImmutable $to, string $locale, bool $forFeed): array;

    /**
     * Avertissement à joindre aux réponses qui contiennent des éléments de cette source
     * ({"text", "version"}, version entière), ou null.
     *
     * @return array{text: string, version: int}|null
     */
    public function disclaimer(string $locale): ?array;
}
