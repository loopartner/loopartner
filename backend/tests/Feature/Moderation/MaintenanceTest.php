<?php

use App\Modules\Accounts\Actions\DeleteAccount;
use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\DecisionMeasure;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Notifications\ModerationDigestNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Minimisation (moderation:prune, décision G), récapitulatif aux admins
| (moderation:notify-moderators) et suppression de compte.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-16 10:00:00');
    Notification::fake();
});

describe('moderation:prune', function (): void {
    it('conserve 12 mois les données personnelles des signalements et 24 mois les décisions', function (): void {
        expect(config('security.retention'))->toMatchArray([
            'moderation_notices_months' => 12,
            'moderation_records_months' => 24,
        ]);
    });

    it('efface l\'identité, l\'explication et la copie 12 mois après la clôture, ou la réception d\'un signalement ouvert', function (): void {
        $closed = Notice::factory()->closed()->create(['closed_at' => now()->subMonths(12)->subDay()]);
        $old = Notice::factory()->create(['created_at' => now()->subMonths(12)->subDay()]);
        $recent = Notice::factory()->closed()->create(['closed_at' => now()->subMonths(11)]);

        $this->artisan('moderation:prune')->assertSuccessful();

        foreach ([$closed, $old] as $notice) {
            $notice->refresh();
            expect($notice->reporter_name)->toBeNull()
                ->and($notice->reporter_email)->toBeNull()
                ->and($notice->reporter_email_hash)->toBeNull()
                ->and($notice->explanation)->toBeNull()
                ->and($notice->content_snapshot)->toBeNull()
                ->and($notice->content_label)->toBeNull()
                ->and($notice->personal_data_purged_at)->not->toBeNull();
        }

        expect($old->fresh()->status)->toBe(NoticeStatus::Open)
            ->and($recent->fresh()->reporter_email)->toBe('alex@example.org');
    });

    it('supprime une décision 24 mois après la fin du délai de recours, avec ses recours et signalements', function (): void {
        $old = Decision::factory()->create([
            'measure' => DecisionMeasure::NoAction, 'ground' => null, 'terms_clause' => null,
            'decided_at' => now()->subMonths(31), 'appeal_deadline_at' => now()->subMonths(25),
        ]);
        $notice = Notice::factory()->create(['status' => NoticeStatus::Decided, 'decision_id' => $old->id, 'closed_at' => now()->subMonths(31)]);
        Appeal::factory()->create(['decision_id' => $old->id, 'status' => AppealStatus::Rejected]);
        $pending = Decision::factory()->create(['measure' => DecisionMeasure::NoAction, 'ground' => null, 'appeal_deadline_at' => now()->subMonths(25)]);
        Appeal::factory()->create(['decision_id' => $pending->id]);
        $recent = Decision::factory()->create(['appeal_deadline_at' => now()->subMonths(23), 'lifted_at' => now()->subMonths(23)]);
        $dismissed = Notice::factory()->closed()->create(['closed_at' => now()->subMonths(25)]);

        $this->artisan('moderation:prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelMissing($notice);
        $this->assertModelMissing($dismissed);
        $this->assertModelExists($pending);
        $this->assertModelExists($recent);
        expect(Appeal::query()->count())->toBe(1);
    });

    it('garde une restriction toujours en vigueur sur un contenu qui existe, pas celle d\'un contenu supprimé', function (): void {
        $article = articleOf(articleAuthor(), 'published');
        $inForce = Decision::factory()->create(['target_ref' => $article->slug, 'appeal_deadline_at' => now()->subMonths(25)]);
        restrictContentWith('article', $article->slug, $inForce->uuid);
        $orphan = Decision::factory()->create(['target_ref' => 'article-supprime', 'appeal_deadline_at' => now()->subMonths(25)]);

        $this->artisan('moderation:prune')->assertSuccessful();

        $this->assertModelExists($inForce);
        $this->assertModelMissing($orphan);
    });
});

describe('moderation:notify-moderators', function (): void {
    it('envoie un récapitulatif sans contenu, au plus une fois par période et seulement s\'il y a du nouveau', function (): void {
        $admin = moderator();
        $adminWithout2fa = projectOwner('admin');
        Notice::factory()->create(['explanation' => 'Contenu très confidentiel', 'due_at' => now()->subHour()]);

        $this->artisan('moderation:notify-moderators')->assertSuccessful();
        $this->artisan('moderation:notify-moderators')->assertSuccessful();

        Notification::assertSentToTimes($admin, ModerationDigestNotification::class, 1);
        Notification::assertNotSentTo($adminWithout2fa, ModerationDigestNotification::class);
        Notification::assertSentTo($admin, ModerationDigestNotification::class, function (ModerationDigestNotification $n) use ($admin): bool {
            $mail = json_encode($n->toMail($admin)->toArray());

            return $n->counts === ['open' => 1, 'urgent' => 0, 'overdue' => 1, 'appeals' => 0]
                && ! str_contains($mail, 'confidentiel');
        });

        $this->travel(31)->minutes();
        $this->artisan('moderation:notify-moderators')->assertSuccessful();
        Notification::assertSentToTimes($admin, ModerationDigestNotification::class, 1);

        Notice::factory()->create();
        $this->artisan('moderation:notify-moderators')->assertSuccessful();
        Notification::assertSentToTimes($admin, ModerationDigestNotification::class, 2);
    });
});

describe('suppression de compte', function (): void {
    it('garde signalements et décisions sans lien avec le compte, et supprime ses recours', function (): void {
        $author = articleAuthor();
        $reporter = projectOwner('particulier');
        $asOwner = Decision::factory()->create(['owner_id' => $author->id]);
        Appeal::factory()->create(['decision_id' => $asOwner->id, 'appellant_id' => $author->id]);
        $against = Notice::factory()->create(['target_owner_id' => $author->id]);
        $sent = Notice::factory()->create(['reporter_id' => $reporter->id, 'reporter_name' => null, 'reporter_email' => null, 'active_key' => hash('sha256', 'cle')]);

        app(DeleteAccount::class)->handle($author);
        app(DeleteAccount::class)->handle($reporter);

        expect($asOwner->fresh()->owner_id)->toBeNull()
            ->and(Appeal::query()->count())->toBe(0)
            ->and($against->fresh()->target_owner_id)->toBeNull()
            ->and($sent->fresh()->reporter_id)->toBeNull()
            ->and($sent->fresh()->active_key)->toBeNull();
    });
});
