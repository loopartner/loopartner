<?php

use App\Modules\Blog\Enums\ArticleNotice;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Notifications\ArticleNotification;
use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Notifications\EventNotification;
use App\Modules\Moderation\Enums\DecisionSource;
use App\Modules\Moderation\Enums\ModerationMail;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Notifications\ModerationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| Décisions de la modération d'Events et du Blog : chacune produit un exposé des motifs
| consultable et contestable (module Moderation), sans changer le fonctionnement du module ;
| l'email du module renvoie vers l'exposé des motifs (décision F).
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-16 10:00:00');
    Notification::fake();
});

describe('Events', function (): void {
    it('enregistre l\'exposé des motifs d\'un refus, que l\'organisateur consulte et conteste', function (): void {
        $event = Event::factory()->pendingReview()->create();
        $reason = 'Le lien d\'inscription pointe vers un site sans rapport avec l\'atelier.';

        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/reject", ['version' => 1, 'reason' => $reason])->assertOk();

        $decision = Decision::query()->sole();
        expect($decision->target_type)->toBe('event')
            ->and($decision->target_ref)->toBe($event->uuid)
            ->and($decision->owner_id)->toBe($event->user_id)
            ->and($decision->facts)->toBe($reason)
            ->and($decision->source)->toBe(DecisionSource::PrePublicationReview)
            ->and($decision->terms_clause)->toBe('publication_rules')
            ->and($decision->reversible)->toBeFalse();

        Notification::assertSentTo($event->user, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::EventRejected
            && in_array('Exposé des motifs de cette décision et possibilité de la contester gratuitement : http://localhost:3000/me/moderation/decisions', $n->toMail($event->user)->introLines, true));
        Notification::assertNotSentTo($event->user, ModerationNotification::class);

        $this->app['auth']->forgetGuards();
        $this->actingAs($event->user)->getJson('/api/v1/me/moderation/decisions')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $decision->uuid)
            ->assertJsonPath('data.0.source', 'pre_publication_review')
            ->assertJsonPath('data.0.facts', $reason);

        $appeal = $this->actingAs($event->user)->postJson("/api/v1/me/moderation/decisions/{$decision->uuid}/appeal", [
            'statement' => 'Le lien a été corrigé : il pointe maintenant vers la page d\'inscription de l\'atelier.',
        ])->assertCreated()->json('data.uuid');

        asModerator()->postJson("/api/v1/admin/moderation/appeals/{$appeal}/decide", ['upheld' => true, 'reason' => 'Le lien corrigé est conforme.'])->assertOk();

        // Le module garde la main : l'événement reste refusé, l'organisateur le soumet de nouveau.
        expect($event->fresh()->status)->toBe(EventStatus::Rejected);
        Notification::assertSentTo($event->user, ModerationNotification::class, fn (ModerationNotification $n): bool => $n->mail === ModerationMail::AppealDecided
            && str_contains(implode(' ', $n->toMail($event->user)->introLines), 'soumettre de nouveau'));
        asModerator()->postJson("/api/v1/admin/moderation/decisions/{$decision->uuid}/lift", ['reason' => 'Levée impossible ici.'])->assertConflict();
    });

    it('enregistre une annulation par un admin et le refus d\'une annonce', function (): void {
        $event = publishedEventOf();
        $announcement = EventAnnouncement::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'event_id' => $event->id, 'user_id' => $event->user_id,
            'body' => 'Rendez-vous sur https://exemple-de-lien.fr pour le programme.', 'has_links' => true,
            'status' => AnnouncementStatus::PendingReview,
        ]);

        asModerator()->postJson("/api/v1/admin/event-announcements/{$announcement->uuid}/reject", ['reason' => 'Le lien de l\'annonce n\'est pas autorisé.'])->assertOk();
        asModerator()->postJson("/api/v1/admin/events/{$event->uuid}/cancel", ['reason' => 'Événement fictif destiné à collecter des inscriptions.'])->assertOk();

        expect(Decision::query()->where('target_type', 'event_announcement')->sole()->target_ref)->toBe($announcement->uuid)
            ->and(Decision::query()->where('target_type', 'event')->sole())
            ->target_ref->toBe($event->slug)
            ->source->toBe(DecisionSource::OwnInitiative);
    });
});

describe('Blog', function (): void {
    it('enregistre le refus et la dépublication d\'un article, avec le lien dans l\'email', function (): void {
        $author = articleAuthor();
        $pending = articleOf($author, 'pending');
        $published = articleOf($author, 'published');

        asModerator()->postJson("/api/v1/admin/articles/{$pending->uuid}/reject", ['version' => $pending->version, 'reason' => 'Le corps de l\'article est un texte publicitaire.'])->assertOk();
        asModerator()->postJson("/api/v1/admin/articles/{$published->uuid}/unpublish", ['reason' => 'Article recopié depuis un autre site.'])->assertOk();

        expect($pending->fresh()->status)->toBe(ArticleStatus::Rejected)
            ->and($published->fresh()->status)->toBe(ArticleStatus::Unpublished);

        $decisions = Decision::query()->orderBy('id')->get();
        expect($decisions)->toHaveCount(2)
            ->and($decisions[0]->target_ref)->toBe($pending->uuid)
            ->and($decisions[0]->source)->toBe(DecisionSource::PrePublicationReview)
            ->and($decisions[1]->target_ref)->toBe($published->slug)
            ->and($decisions[1]->source)->toBe(DecisionSource::OwnInitiative);

        Notification::assertSentTo($author, ArticleNotification::class, fn (ArticleNotification $n): bool => $n->notice === ArticleNotice::Unpublished
            && str_contains(implode(' ', $n->toMail($author)->introLines), '/me/moderation/decisions'));
    });

    it('n\'ajoute pas le lien aux emails qui ne sont pas des décisions restrictives', function (): void {
        $author = articleAuthor();
        $pending = articleOf($author, 'pending');

        asModerator()->postJson("/api/v1/admin/articles/{$pending->uuid}/approve", ['version' => $pending->version])->assertOk();

        expect(Decision::query()->count())->toBe(0);
        Notification::assertSentTo($author, ArticleNotification::class, fn (ArticleNotification $n): bool => $n->notice === ArticleNotice::Approved
            && ! str_contains(implode(' ', $n->toMail($author)->introLines), '/me/moderation/decisions'));
    });
});
