<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\EventContent;
use App\Modules\Events\Support\EventPresenter;
use App\Modules\Events\Support\OrganizerDirectory;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Événement tel que le public le voit (agenda et page publique), identifié par son slug.
 *
 * Confidentialité : jamais le lien de connexion, ni l'uuid, ni d'identifiant interne ; l'adresse
 * et le code postal sont masqués quand l'organisateur les réserve aux inscrits ; l'organisateur
 * est présenté par son profil public ou par le nom choisi pour l'événement (OrganizerDirectory).
 *
 * "registration.privacy" annonce, avant l'inscription, ce que l'organisateur verra de l'inscrit
 * (son nom et son type de compte, jamais son email) : le frontend l'affiche clairement.
 *
 * Relations à charger : categories, cover, user.roles.
 *
 * @mixin Event
 */
class PublicEventResource extends JsonResource
{
    /**
     * @var array<string, string>
     */
    private const PRIVACY_NOTICE = [
        'fr' => 'En vous inscrivant, votre nom et votre type de compte seront visibles par l\'organisateur. Votre adresse email ne lui est jamais communiquée.',
        'en' => 'If you register, your name and account type will be visible to the organiser. Your email address is never shared with them.',
    ];

    public function __construct(Event $event, private OrganizerDirectory $organizers)
    {
        parent::__construct($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Event $event */
        $event = $this->resource;
        $content = EventPresenter::content(EventContent::of($event), withOnlineUrl: false, withPrivateAddress: false, categories: $event->categories);
        unset($content['online_url'], $content['organizer_name']);

        return [
            'slug' => $event->slug,
            'status' => $event->hasEnded() ? 'past' : 'published',
            ...$content,
            'cover_url' => $event->cover?->url(),
            'organizer' => $this->organizers->present($event),
            'registration' => [
                'mode' => $event->is_free ? 'internal' : 'external',
                'is_open' => $event->acceptsRegistrations(),
                'is_full' => $event->is_free && $event->isFull(),
                'spots_left' => $event->is_free ? max(0, $event->registrationLimit() - $event->registrations_count) : null,
                'privacy' => $event->is_free ? [
                    'visible_to_organizer' => ['name', 'account_type'],
                    'notice' => self::PRIVACY_NOTICE[Locale::fromRequest($request)],
                ] : null,
            ],
            'published_at' => $event->published_at?->toIso8601ZuluString(),
        ];
    }
}
