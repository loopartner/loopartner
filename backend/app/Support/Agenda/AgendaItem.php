<?php

namespace App\Support\Agenda;

use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Élément de l'agenda unifié, fourni par un module (AgendaSource) en données simples : jamais
 * un modèle Eloquent d'un module.
 *
 * - source : clé de la source (appointments, events, compliance) ; kind : genre d'élément
 *   (appointment, event_registration, event_organized, deadline) ; ref : identifiant public
 *   (uuid ou slug), jamais l'id interne ;
 * - title : titre affichable, **le seul texte repris dans l'abonnement** avec les dates ;
 * - startsAt, endsAt : UTC ; allDay : journée entière (échéances), endsAt est alors le
 *   lendemain ;
 * - timezone : fuseau d'origine (null pour une journée entière) ; status ; role : place de
 *   l'utilisateur (client, provider, participant, organizer) ;
 * - feedNote : texte fixe facultatif repris dans la description de l'abonnement (avertissement
 *   du suivi administratif), jamais une donnée saisie.
 */
final readonly class AgendaItem
{
    public function __construct(
        public string $source,
        public string $kind,
        public string $ref,
        public string $title,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $allDay,
        public ?string $timezone,
        public string $status,
        public ?string $role = null,
        public ?string $feedNote = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'kind' => $this->kind,
            'ref' => $this->ref,
            'title' => $this->title,
            'all_day' => $this->allDay,
            'date' => $this->allDay ? $this->startsAt->toDateString() : null,
            'starts_at' => $this->startsAt->toIso8601ZuluString(),
            'ends_at' => $this->endsAt->toIso8601ZuluString(),
            'timezone' => $this->timezone,
            'local' => $this->allDay || $this->timezone === null ? null : [
                'starts_at' => LocalTime::present($this->startsAt, $this->timezone)['date_time'],
                'ends_at' => LocalTime::present($this->endsAt, $this->timezone)['date_time'],
                'offset' => LocalTime::present($this->startsAt, $this->timezone)['offset'],
            ],
            'status' => $this->status,
            'role' => $this->role,
        ];
    }
}
