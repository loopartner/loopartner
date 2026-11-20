<?php

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Support\Links\ExternalLink;
use App\Support\Links\LinkInspection;

/**
 * Liens d'un événement mis en évidence pour la modération : chaque lien (champs de lien et liens
 * écrits dans le texte) et la liste des domaines, avec des signaux d'alerte.
 *
 * - punycode : nom de domaine internationalisé, qui peut imiter un domaine connu ;
 * - previously_approved : domaine déjà présent dans un autre événement publié du même
 *   organisateur (un nouveau domaine mérite plus d'attention).
 */
final class EventLinks
{
    /** @var list<string> */
    private const URL_FIELDS = ['online_url', 'external_url', 'ticket_url'];

    /** @var list<string> */
    private const TEXT_FIELDS = ['title', 'description', 'organizer_name', 'venue_name', 'address_line', 'city'];

    private const HISTORY_LIMIT = 50;

    /**
     * @param  array<string, mixed>  $content
     * @return array{links: list<array{field: string, link: string, host: string|null}>, domains: list<array{host: string, fields: list<string>, punycode: bool, previously_approved: bool}>}
     */
    public static function inspect(array $content, ?int $organizerId, ?int $eventId): array
    {
        return LinkInspection::inspect(self::links($content), self::approvedHosts($organizerId, $eventId));
    }

    /**
     * Noms d'hôte des liens d'un contenu.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function hosts(array $content): array
    {
        return array_values(array_unique(array_filter(array_column(self::links($content), 'host'))));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array{field: string, link: string, host: string|null}>
     */
    private static function links(array $content): array
    {
        $links = [];

        foreach (self::URL_FIELDS as $field) {
            $url = $content[$field] ?? null;

            if (is_string($url) && $url !== '') {
                $links[] = ['field' => $field, 'link' => $url, 'host' => ExternalLink::host($url)];
            }
        }

        foreach (self::TEXT_FIELDS as $field) {
            $text = $content[$field] ?? null;

            if (is_string($text)) {
                foreach (ExternalLink::extract($text) as $link) {
                    $links[] = ['field' => $field, 'link' => $link, 'host' => ExternalLink::textHost($link)];
                }
            }
        }

        return $links;
    }

    /**
     * Domaines des autres événements déjà validés de l'organisateur.
     *
     * @return list<string>
     */
    private static function approvedHosts(?int $organizerId, ?int $eventId): array
    {
        if ($organizerId === null) {
            return [];
        }

        $hosts = [];

        Event::query()
            ->where('user_id', $organizerId)
            ->when($eventId !== null, fn ($query) => $query->whereKeyNot($eventId))
            ->whereIn('status', [EventStatus::Published->value, EventStatus::Past->value])
            ->latest('published_at')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->each(function (Event $event) use (&$hosts): void {
                $content = [];
                foreach ([...self::URL_FIELDS, ...self::TEXT_FIELDS] as $field) {
                    $content[$field] = $event->getAttribute($field);
                }
                array_push($hosts, ...self::hosts($content));
            });

        return array_values(array_unique($hosts));
    }
}
