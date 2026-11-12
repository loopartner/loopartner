<?php

namespace App\Modules\Events\Support;

use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Events\Models\Event;

/**
 * Présentation publique de l'organisateur d'événements : le profil public d'un prestataire
 * (résumé de l'Action publique GetProviderSummaries du module Accounts, en une requête pour
 * toute une page), sinon le nom d'organisateur choisi pour l'événement.
 *
 * Jamais d'email, de nom d'utilisateur ni d'identifiant interne. Les événements doivent avoir
 * la relation user.roles chargée.
 *
 * @phpstan-import-type ProviderSummary from GetProviderSummaries
 */
final class OrganizerDirectory
{
    /**
     * @param  array<int, ProviderSummary>  $providers  résumés indexés par identifiant d'utilisateur
     */
    private function __construct(private array $providers) {}

    /**
     * @param  iterable<Event>  $events
     */
    public static function for(iterable $events): self
    {
        $providerIds = [];

        foreach ($events as $event) {
            if ($event->user !== null && Organizers::isProvider($event->user)) {
                $providerIds[] = $event->user->id;
            }
        }

        return new self(app(GetProviderSummaries::class)->handle($providerIds));
    }

    /**
     * @return array{kind: string, name: string|null, slug: string|null, headline: string|null, logo_url: string|null}
     */
    public function present(Event $event): array
    {
        $summary = $event->user_id !== null ? ($this->providers[$event->user_id] ?? null) : null;

        if ($summary !== null) {
            return [
                'kind' => 'provider',
                'name' => $summary['display_name'],
                'slug' => $summary['slug'],
                'headline' => $summary['headline'],
                'logo_url' => $summary['logo_url'],
            ];
        }

        return [
            'kind' => 'named',
            'name' => $event->organizer_name,
            'slug' => null,
            'headline' => null,
            'logo_url' => null,
        ];
    }
}
