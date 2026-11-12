<?php

namespace App\Modules\Events\Support;

use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use App\Modules\Events\Enums\AddressVisibility;
use App\Modules\Events\Enums\EventFormat;
use Illuminate\Support\Collection;

/**
 * Présentation du contenu d'un événement (EventContent) dans les réponses de l'API : la même
 * forme pour la version publiée, une révision proposée, la page publique et la modération.
 *
 * Les champs sensibles ne sont inclus que sur demande :
 * - le lien de connexion (online_url) : organisateur, inscrits, admins pendant la modération ;
 * - l'adresse et le code postal quand address_visibility vaut "registrants" : mêmes personnes.
 */
final class EventPresenter
{
    /**
     * @param  array<string, mixed>  $content
     * @param  Collection<int, ServiceCategory>|null  $categories  catégories déjà chargées (sinon lues par slug)
     * @return array<string, mixed>
     */
    public static function content(array $content, bool $withOnlineUrl, bool $withPrivateAddress, ?Collection $categories = null): array
    {
        $format = EventFormat::from((string) $content['format']);
        $timezone = (string) $content['timezone'];
        $visibility = AddressVisibility::tryFrom((string) ($content['address_visibility'] ?? '')) ?? AddressVisibility::Public;
        $showAddress = $visibility === AddressVisibility::Public || $withPrivateAddress;

        $categories ??= ServiceCategory::query()
            ->whereIn('slug', (array) ($content['categories'] ?? []))
            ->orderBy('slug')
            ->get();

        return [
            'title' => $content['title'],
            'description' => $content['description'],
            'type' => $content['type'],
            'format' => $format->value,
            'language' => $content['language'],
            'organizer_name' => $content['organizer_name'] ?? null,
            'categories' => ServiceCategoryResource::collection($categories),
            'starts_at' => $content['starts_at'],
            'ends_at' => $content['ends_at'],
            'timezone' => $timezone,
            'local' => [
                'starts_at' => is_string($content['starts_at']) ? EventContent::localTime($content['starts_at'], $timezone) : null,
                'ends_at' => is_string($content['ends_at']) ? EventContent::localTime($content['ends_at'], $timezone) : null,
            ],
            'venue' => $format->hasVenue() ? [
                'name' => $content['venue_name'],
                'address_line' => $showAddress ? $content['address_line'] : null,
                'postal_code' => $showAddress ? $content['postal_code'] : null,
                'city' => $content['city'],
                'country' => $content['country'],
                'address_visibility' => $visibility->value,
            ] : null,
            'online_url' => $withOnlineUrl && $format->isOnline() ? $content['online_url'] : null,
            'external_url' => $content['external_url'],
            'is_free' => $content['is_free'],
            'ticket_url' => $content['ticket_url'],
            'capacity' => $content['capacity'],
        ];
    }
}
