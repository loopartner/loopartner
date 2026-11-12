<?php

namespace App\Modules\Events\Http\Resources;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Support\EventContent;
use App\Modules\Events\Support\EventLinks;
use App\Modules\Events\Support\EventPresenter;
use App\Modules\Events\Support\OrganizerHistory;
use App\Support\AdminAudit;
use App\Support\Links\ExternalLink;

/**
 * Vues de la modération (admins avec 2FA) : contenu complet de l'élément à vérifier, liens et
 * domaines mis en évidence, historique de l'organisateur et décisions déjà prises.
 *
 * Le lien de connexion n'est montré en clair que pendant la modération (élément en attente) ;
 * sinon seul son nom de domaine l'est. Aucun email ni identifiant interne d'utilisateur.
 */
final class ModerationView
{
    /**
     * @return array<string, mixed>
     */
    public static function event(Event $event): array
    {
        $content = EventContent::of($event);
        $inReview = $event->status === EventStatus::PendingReview;
        $revision = $event->pendingRevision;

        return [
            'uuid' => $event->uuid,
            'slug' => $event->slug,
            'status' => $event->status->value,
            'version' => $event->version,
            'submitted_at' => $event->submitted_at?->toIso8601ZuluString(),
            'published_at' => $event->published_at?->toIso8601ZuluString(),
            'content' => [
                ...EventPresenter::content($content, withOnlineUrl: $inReview, withPrivateAddress: true, categories: $event->categories),
                'online_url_host' => is_string($content['online_url']) ? ExternalLink::host($content['online_url']) : null,
                'cover_url' => $event->cover?->url(),
            ],
            ...EventLinks::inspect($content, $event->user_id, $event->id),
            'registrations_count' => $event->registrations_count,
            'rejection_reason' => $event->rejection_reason,
            'cancelled_at' => $event->cancelled_at?->toIso8601ZuluString(),
            'cancelled_by' => $event->cancelled_by?->value,
            'cancellation_reason' => $event->cancellation_reason,
            'pending_revision' => $revision instanceof EventRevision ? [
                'uuid' => $revision->uuid,
                'version' => $revision->version,
                'submitted_at' => $revision->submitted_at->toIso8601ZuluString(),
                'changed_fields' => $revision->changed_fields,
            ] : null,
            'organizer' => app(OrganizerHistory::class)->for($event->user),
            'decisions' => self::decisions('event', $event->uuid),
        ];
    }

    /**
     * Révision : version proposée et version publiée, côte à côte.
     *
     * @return array<string, mixed>
     */
    public static function revision(EventRevision $revision): array
    {
        $event = $revision->event;
        $proposed = collect($revision->payload)->except('cover')->all();
        $current = EventContent::of($event);
        $inReview = $revision->isPending();

        return [
            'uuid' => $revision->uuid,
            'status' => $revision->status->value,
            'version' => $revision->version,
            'submitted_at' => $revision->submitted_at->toIso8601ZuluString(),
            'changed_fields' => $revision->changed_fields,
            'rejection_reason' => $revision->rejection_reason,
            'event' => [
                'uuid' => $event->uuid,
                'slug' => $event->slug,
                'status' => $event->status->value,
                'version' => $event->version,
                'registrations_count' => $event->registrations_count,
            ],
            'proposed' => [
                ...EventPresenter::content($proposed, withOnlineUrl: $inReview, withPrivateAddress: true),
                'cover_url' => match ($revision->coverOperation()) {
                    EventRevision::COVER_REPLACE => $revision->cover?->url(),
                    EventRevision::COVER_REMOVE => null,
                    default => $event->cover?->url(),
                },
            ],
            'current' => [
                ...EventPresenter::content($current, withOnlineUrl: $inReview, withPrivateAddress: true, categories: $event->categories),
                'cover_url' => $event->cover?->url(),
            ],
            ...EventLinks::inspect($proposed, $event->user_id, $event->id),
            'organizer' => app(OrganizerHistory::class)->for($event->user),
            'decisions' => self::decisions('revision', $revision->uuid),
        ];
    }

    /**
     * Annonce contenant un lien : texte complet, liens et domaines, événement et organisateur.
     *
     * @return array<string, mixed>
     */
    public static function announcement(EventAnnouncement $announcement): array
    {
        $event = $announcement->event;
        $links = EventLinks::inspect(['description' => $announcement->body], $event->user_id, $event->id);

        return [
            'uuid' => $announcement->uuid,
            'status' => $announcement->status->value,
            'body' => $announcement->body,
            'created_at' => $announcement->created_at?->toIso8601ZuluString(),
            'rejection_reason' => $announcement->rejection_reason,
            'recipients_count' => $announcement->recipients_count,
            'event' => [
                'uuid' => $event->uuid,
                'slug' => $event->slug,
                'title' => $event->title,
                'status' => $event->status->value,
                'starts_at' => $event->starts_at->toIso8601ZuluString(),
                'registrations_count' => $event->registrations_count,
            ],
            'links' => array_map(fn (array $link): array => [...$link, 'field' => 'body'], $links['links']),
            'domains' => array_map(fn (array $domain): array => [...$domain, 'fields' => ['body']], $links['domains']),
            'organizer' => app(OrganizerHistory::class)->for($event->user),
            'decisions' => self::decisions('announcement', $announcement->uuid),
        ];
    }

    /**
     * Décisions déjà prises sur l'élément (journal d'audit), avec le nom de l'admin.
     *
     * @return list<array<string, mixed>>
     */
    public static function decisions(string $subjectType, string $uuid): array
    {
        $history = AdminAudit::historyOf($subjectType, $uuid);
        $admins = User::query()->whereKey($history->pluck('admin_id')->unique()->all())->pluck('name', 'id');

        return $history->map(fn (AdminAuditLog $entry): array => [
            'action' => $entry->action,
            'version' => $entry->version,
            'reason' => $entry->reason,
            'admin' => $admins[$entry->admin_id] ?? null,
            'at' => $entry->created_at?->toIso8601ZuluString(),
        ])->values()->all();
    }
}
