<?php

use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Notifications\EventNotification;
use App\Modules\Moderation\Support\TargetTypes;
use App\Support\Moderation\ModerationTargets;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| Restriction de visibilité (module Moderation, DSA art. 17 §1 a) : chaque adaptateur masque son
| contenu sur toutes les surfaces où il serait visible, sans toucher à son statut, et la levée
| par la même décision le rend tel qu'il était.
*/

/**
 * Pages de détail publiques d'un contenu fourni par les utilisateurs => type signalable. Toute
 * nouvelle page de ce genre (offres, avis…) doit être ajoutée ici avec son adaptateur.
 */
const PUBLIC_CONTENT_ROUTES = [
    'api/v1/providers/{slug}' => 'provider_profile',
    'api/v1/projects/{uuid}' => 'project',
    'api/v1/events/{slug}' => 'event',
    'api/v1/articles/{slug}' => 'article',
    'api/v1/offers/{slug}' => 'offer',
    'api/v1/providers/{slug}/booking' => 'appointment_type',
];

/**
 * Routes publiques à paramètre qui ne présentent pas un contenu fourni par un utilisateur.
 */
const NON_CONTENT_ROUTES = [
    'api/v1/legal/documents/{type}',
    'api/v1/compliance/countries/{country}/questionnaire',
    'api/v1/compliance/countries/{country}/journeys',
    'api/v1/compliance/calendar/{token}.ics',
    'api/v1/events/{slug}/calendar.ics',
    'api/v1/providers/{slug}/articles',
    'api/v1/providers/{slug}/offers',
    'api/v1/auth/email/verify/{id}/{hash}',
];

it('déclare un adaptateur et un nom pour chaque contenu public', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('GET', $route->methods(), true)
            && str_starts_with($route->uri(), 'api/v1/')
            && ! str_starts_with($route->uri(), 'api/v1/me/')
            && ! str_starts_with($route->uri(), 'api/v1/admin/')
            && ! str_starts_with($route->uri(), 'api/v1/moderation/')
            && str_contains($route->uri(), '{'))
        ->map(fn ($route): string => $route->uri())
        ->reject(fn (string $uri): bool => in_array($uri, NON_CONTENT_ROUTES, true))
        ->values()
        ->all();

    expect($routes)->toEqualCanonicalizing(array_keys(PUBLIC_CONTENT_ROUTES));

    foreach (PUBLIC_CONTENT_ROUTES as $type) {
        expect(ModerationTargets::has($type))->toBeTrue()
            ->and(ModerationTargets::get($type)->publiclyReportable())->toBeTrue();
    }

    foreach (ModerationTargets::types() as $type) {
        expect(TargetTypes::LABELS)->toHaveKey($type);
    }
});

describe('profil prestataire', function (): void {
    it('le traite comme non publié partout, puis le rétablit à la levée', function (): void {
        $provider = browsingProvider();
        $slug = providerSlug($provider);
        $decision = restrictContent('provider_profile', $slug);

        $this->getJson('/api/v1/providers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/providers/{$slug}")->assertNotFound();
        $this->getJson("/api/v1/providers/{$slug}/articles")->assertNotFound();
        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/providers/{$slug}/contact", [
            'message' => 'Bonjour, je souhaite échanger sur un projet de site.',
        ])->assertNotFound();
        expect(app(HasPublishedProviderProfile::class)->handle($provider))->toBeFalse();
        $this->actingAs($provider)->getJson('/api/v1/projects')->assertForbidden();
        $this->actingAs($provider)->getJson('/api/v1/me/provider-profile')
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.moderation', ['restricted' => true, 'decision' => $decision]);

        ModerationTargets::get('provider_profile')->lift($slug, (string) Str::uuid());
        $this->getJson("/api/v1/providers/{$slug}")->assertNotFound();

        ModerationTargets::get('provider_profile')->lift($slug, $decision);
        $this->getJson("/api/v1/providers/{$slug}")->assertOk();
        $this->actingAs($provider)->getJson('/api/v1/projects')->assertOk();
    });
});

describe('projet', function (): void {
    it('le retire de la consultation et fait expirer ses propositions en attente', function (): void {
        $provider = browsingProvider();
        $project = publishedProjectOf();
        $proposal = pendingProposal(browsingProvider(), $project);
        $decision = restrictContent('project', $project->uuid);

        $this->actingAs($provider)->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($provider)->getJson("/api/v1/projects/{$project->uuid}")->assertNotFound();
        $this->actingAs($provider)->postJson("/api/v1/projects/{$project->uuid}/proposals", [
            'message' => 'Bonjour, je peux réaliser ce site rapidement.',
        ])->assertNotFound();
        $this->actingAs($project->user)->getJson("/api/v1/me/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.moderation.restricted', true);

        $this->artisan('match-requests:expire')->assertSuccessful();
        expect($proposal->fresh()->status)->toBe(MatchRequestStatus::Expired)
            ->and($project->fresh()->status->value)->toBe('published');

        ModerationTargets::get('project')->lift($project->uuid, $decision);
        $this->actingAs($provider)->getJson("/api/v1/projects/{$project->uuid}")->assertOk();
    });

    it('n\'existe que pour un prestataire qui peut consulter les projets', function (): void {
        $project = publishedProjectOf();
        $adapter = ModerationTargets::get('project');

        expect($adapter->findVisible($project->uuid, null))->toBeNull()
            ->and($adapter->findVisible($project->uuid, projectOwner('particulier')))->toBeNull()
            ->and($adapter->findVisible($project->uuid, browsingProvider())?->ownerId)->toBe($project->user_id);
    });
});

describe('événement', function (): void {
    it('le retire de l\'agenda, ferme inscriptions, rappels et annonces, et prévient les inscrits', function (): void {
        Notification::fake();
        $event = publishedEventOf();
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);
        $decision = restrictContent('event', $event->slug);

        $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/events/{$event->slug}")->assertNotFound();
        $this->getJson("/api/v1/events/{$event->slug}/calendar.ics")->assertNotFound();
        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/events/{$event->slug}/registration")->assertNotFound();
        $this->actingAs($event->user)->postJson("/api/v1/me/events/{$event->uuid}/announcements", [
            'body' => 'Rendez-vous à l\'accueil du bâtiment dix minutes avant le début.',
        ])->assertConflict();
        $this->actingAs($event->user)->getJson("/api/v1/me/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.moderation', ['restricted' => true, 'decision' => $decision]);
        $this->actingAs($attendee)->getJson("/api/v1/me/event-registrations/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.event.hidden_by_moderation', true);

        Notification::assertSentTo($attendee, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::HiddenByModeration);

        $this->travel(9)->days();
        $this->artisan('events:send-reminders')->assertSuccessful();
        Notification::assertNotSentTo($attendee, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::Reminder);

        $this->actingAs($attendee)->deleteJson("/api/v1/events/{$event->slug}/registration")->assertNoContent();

        ModerationTargets::get('event')->lift($event->slug, $decision);
        $this->getJson("/api/v1/events/{$event->slug}")->assertOk();
    });
});

describe('annonce aux inscrits', function (): void {
    it('n\'est signalable que par un inscrit et disparaît de sa page une fois masquée', function (): void {
        $event = publishedEventOf();
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);
        $announcement = EventAnnouncement::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'event_id' => $event->id, 'user_id' => $event->user_id,
            'body' => 'Le programme détaillé est disponible sur la page de l\'événement.',
            'status' => AnnouncementStatus::Published, 'published_at' => now(),
        ]);
        $adapter = ModerationTargets::get('event_announcement');

        expect($adapter->findVisible($announcement->uuid, projectOwner('particulier')))->toBeNull()
            ->and($adapter->findVisible($announcement->uuid, null))->toBeNull()
            ->and($adapter->findVisible($announcement->uuid, $attendee)?->ownerId)->toBe($event->user_id);

        restrictContent('event_announcement', $announcement->uuid);

        $this->actingAs($attendee)->getJson("/api/v1/me/event-registrations/{$event->slug}")
            ->assertOk()
            ->assertJsonCount(0, 'data.announcements');
        $this->actingAs($event->user)->getJson("/api/v1/me/events/{$event->uuid}/announcements")
            ->assertOk()
            ->assertJsonPath('data.0.moderation.restricted', true);
    });
});

describe('article', function (): void {
    it('le retire de la lecture publique sans changer son statut', function (): void {
        $author = articleAuthor('prestataire');
        $article = articleOf($author, 'published');
        $decision = restrictContent('article', $article->slug);

        $this->getJson('/api/v1/articles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/articles/{$article->slug}")->assertNotFound();
        $this->getJson('/api/v1/articles/sitemap')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/providers/'.providerSlug($author).'/articles')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($author)->getJson("/api/v1/me/articles/{$article->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.moderation.restricted', true);

        ModerationTargets::get('article')->lift($article->slug, $decision);
        $this->getJson("/api/v1/articles/{$article->slug}")->assertOk();
    });
});

describe('offre', function (): void {
    it('la retire de la recherche, de sa page et de la page du prestataire sans changer son statut', function (): void {
        $provider = marketplaceProvider();
        $offer = publishedOfferOf($provider);
        $decision = restrictContent('offer', (string) $offer->slug);

        $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/offers/{$offer->slug}")->assertNotFound();
        $this->getJson('/api/v1/providers/'.providerSlug($provider).'/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($provider)->getJson("/api/v1/me/offers/{$offer->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.moderation.restricted', true);

        ModerationTargets::get('offer')->lift((string) $offer->slug, $decision);
        $this->getJson("/api/v1/offers/{$offer->slug}")->assertOk();
    });

    it('cache les offres d\'un profil prestataire masqué', function (): void {
        $provider = marketplaceProvider();
        $offer = publishedOfferOf($provider);
        restrictContent('provider_profile', providerSlug($provider));

        $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/offers/{$offer->slug}")->assertNotFound();
    });
});

describe('messagerie', function (): void {
    it('garde un message masqué à sa place, sans son texte, pour les deux participants', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $message = postMessage($provider, $conversation, 'Voici mon numéro pour continuer ailleurs.');
        $adapter = ModerationTargets::get('message');

        expect($adapter->publiclyReportable())->toBeFalse()
            ->and($adapter->findVisible($message->uuid, projectOwner('particulier')))->toBeNull()
            ->and($adapter->findVisible($message->uuid, $owner)?->ownerId)->toBe($provider->id);

        restrictContent('message', $message->uuid);

        foreach ([$owner, $provider] as $participant) {
            $this->actingAs($participant)->getJson("/api/v1/me/conversations/{$conversation->uuid}/messages")
                ->assertOk()
                ->assertJsonPath('data.0.body', null);
            $this->actingAs($participant)->getJson('/api/v1/me/conversations')
                ->assertOk()
                ->assertJsonPath('data.0.last_message.excerpt', null);
        }
    });

    it('masque le texte d\'une demande, que seul son destinataire peut signaler', function (): void {
        $provider = browsingProvider();
        $project = publishedProjectOf();
        $proposal = pendingProposal($provider, $project);
        $adapter = ModerationTargets::get('match_request');

        expect($adapter->findVisible($proposal->uuid, $provider))->toBeNull()
            ->and($adapter->findVisible($proposal->uuid, $project->user)?->ownerId)->toBe($provider->id);

        restrictContent('match_request', $proposal->uuid);

        $this->actingAs($project->user)->getJson("/api/v1/me/match-requests/{$proposal->uuid}")
            ->assertOk()
            ->assertJsonPath('data.message', null);
    });
});
