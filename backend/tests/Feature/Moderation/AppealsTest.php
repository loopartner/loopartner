<?php

use App\Models\User;
use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\ModerationMail;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Notifications\ModerationNotification;
use App\Support\Moderation\ModerationTargets;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Recours interne (aligné sur DSA art. 20) : l'auteur du contenu contre une restriction, le
| signalant contre une absence de mesure ; examen motivé par un admin.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-16 10:00:00');
    Notification::fake();
});

const APPEAL_TEXT = 'J\'ai retiré le lien vers le site de paiement : merci de réexaminer mon article.';

/**
 * Restriction d'un article d'un auteur (entrepreneur) par un admin ; renvoie [auteur, slug, décision, admin].
 *
 * @return array{0: User, 1: string, 2: Decision, 3: User}
 */
function restrictedArticle(): array
{
    $admin = moderator();
    $author = articleAuthor();
    $slug = (string) articleOf($author, 'published')->slug;

    $uuid = asModerator($admin)->postJson("/api/v1/admin/moderation/targets/article/{$slug}/decisions", [
        'measure' => 'restrict_visibility',
        'ground' => 'terms',
        'category' => 'scam_fraud',
        'terms_clause' => 'prohibited_content',
        'facts' => 'L\'article renvoie vers un site qui demande un paiement pour des gains garantis.',
        'version' => ModerationTargets::get('article')->inspect($slug)?->version,
    ])->assertCreated()->json('data.uuid');

    app('auth')->forgetGuards();

    return [$author, $slug, Decision::query()->where('uuid', $uuid)->sole(), $admin];
}

/**
 * Signalement d'un membre classé sans mesure ; renvoie [signalant, signalement, décision].
 *
 * @return array{0: User, 1: Notice, 2: Decision}
 */
function dismissedMemberNotice(): array
{
    $member = projectOwner('particulier');
    $slug = (string) articleOf(articleAuthor(), 'published')->slug;
    test()->actingAs($member)->postJson('/api/v1/moderation/notices', [
        'target_type' => 'article', 'target_ref' => $slug, 'category' => 'scam_fraud',
        'explanation' => 'Cet article promet des gains garantis à ses lecteurs.', 'good_faith' => true,
    ])->assertCreated();

    asModerator()->postJson("/api/v1/admin/moderation/targets/article/{$slug}/decisions", [
        'measure' => 'no_action',
        'facts' => 'L\'article décrit un placement sans promettre de gain garanti.',
        'version' => ModerationTargets::get('article')->inspect($slug)?->version,
    ])->assertCreated();

    app('auth')->forgetGuards();
    $notice = Notice::query()->with('decision')->latest('id')->firstOrFail();

    return [$member, $notice, $notice->decision];
}

describe('recours de l\'auteur du contenu', function (): void {
    it('rend le contenu visible quand le recours est accepté, et prévient l\'auteur', function (): void {
        [$author, $slug, $decision] = restrictedArticle();
        $reviewer = moderator();

        $appeal = $this->actingAs($author)->postJson("/api/v1/me/moderation/decisions/{$decision->uuid}/appeal", ['statement' => APPEAL_TEXT])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.uuid');
        $this->actingAs($author)->postJson("/api/v1/me/moderation/decisions/{$decision->uuid}/appeal", ['statement' => APPEAL_TEXT])->assertConflict();
        $this->actingAs($author)->getJson("/api/v1/me/moderation/decisions/{$decision->uuid}")
            ->assertJsonPath('data.redress.internal_appeal.pending', true)
            ->assertJsonPath('data.redress.internal_appeal.available', false);

        asModerator($reviewer)->getJson('/api/v1/admin/moderation/appeals')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $appeal)
            ->assertJsonPath('data.0.statement', APPEAL_TEXT);
        asModerator($reviewer)->getJson("/api/v1/admin/moderation/appeals/{$appeal}")
            ->assertOk()
            ->assertJsonPath('data.contested_decision.uuid', $decision->uuid);

        asModerator($reviewer)->postJson("/api/v1/admin/moderation/appeals/{$appeal}/decide", ['upheld' => true, 'reason' => 'Le lien a bien été retiré.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'upheld');

        $this->getJson("/api/v1/articles/{$slug}")->assertOk();
        expect($decision->fresh()->lifted_at)->not->toBeNull();
        Notification::assertSentTo($author, ModerationNotification::class, fn (ModerationNotification $n): bool => $n->mail === ModerationMail::AppealDecided
            && str_contains(implode(' ', $n->toMail($author)->introLines), 'la décision est annulée')
            && str_contains(implode(' ', $n->toMail($author)->introLines), 'Le lien a bien été retiré.'));
        Notification::assertNotSentTo($author, ModerationNotification::class, fn (ModerationNotification $n): bool => $n->mail === ModerationMail::RestrictionLifted);

        expect(json_encode(securityLog('moderation.appeal_submitted')))->not->toContain('paiement')
            ->and(securityLog('moderation.appeal_decided')[0]->context)->toMatchArray(['appeal' => $appeal, 'outcome' => 'upheld']);
    });

    it('maintient la décision quand le recours est rejeté', function (): void {
        [$author, $slug, $decision] = restrictedArticle();
        $appeal = $this->actingAs($author)->postJson("/api/v1/me/moderation/decisions/{$decision->uuid}/appeal", ['statement' => APPEAL_TEXT])->json('data.uuid');

        asModerator()->postJson("/api/v1/admin/moderation/appeals/{$appeal}/decide", ['upheld' => false, 'reason' => 'Le lien est toujours présent dans l\'article.'])->assertOk();
        asModerator()->postJson("/api/v1/admin/moderation/appeals/{$appeal}/decide", ['upheld' => true, 'reason' => 'Changement d\'avis.'])->assertConflict();

        $this->getJson("/api/v1/articles/{$slug}")->assertNotFound();
        Notification::assertSentTo($author, ModerationNotification::class, fn (ModerationNotification $n): bool => $n->mail === ModerationMail::AppealDecided
            && str_contains(implode(' ', $n->toMail($author)->introLines), 'la décision est maintenue'));
    });

    it('limite le recours au délai, à trois par décision et à une restriction en vigueur (409)', function (): void {
        [$author, , $decision] = restrictedArticle();
        $url = "/api/v1/me/moderation/decisions/{$decision->uuid}/appeal";

        foreach (range(1, 3) as $i) {
            $uuid = $this->actingAs($author)->postJson($url, ['statement' => APPEAL_TEXT])->assertCreated()->json('data.uuid');
            Appeal::query()->where('uuid', $uuid)->update(['status' => AppealStatus::Rejected->value]);
        }
        $this->actingAs($author)->postJson($url, ['statement' => APPEAL_TEXT])->assertConflict();

        [$other, , $late] = restrictedArticle();
        $this->travelTo(now()->addMonths(6)->addDay());
        $this->actingAs($other)->postJson("/api/v1/me/moderation/decisions/{$late->uuid}/appeal", ['statement' => APPEAL_TEXT])
            ->assertConflict()
            ->assertJsonPath('message', 'Le délai de recours contre cette décision est expiré.');
    });

    it('cache la décision d\'un autre (404) et valide le texte (422)', function (): void {
        [$author, , $decision] = restrictedArticle();

        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/me/moderation/decisions/{$decision->uuid}/appeal", ['statement' => APPEAL_TEXT])->assertNotFound();
        $this->actingAs($author)->postJson("/api/v1/me/moderation/decisions/{$decision->uuid}/appeal", ['statement' => 'Trop court.'])->assertUnprocessable();
        $this->actingAs($author)->postJson("/api/v1/me/moderation/decisions/{$decision->uuid}/appeal", ['statement' => APPEAL_TEXT, 'token' => str_repeat('a', 48)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/me/moderation/decisions/{$decision->uuid}/appeal", ['statement' => APPEAL_TEXT])->assertUnauthorized();
    });

    it('exige un autre admin quand le contrôle à quatre yeux est activé (403)', function (): void {
        config(['moderation.require_distinct_appeal_reviewer' => true]);
        [$author, , $decision, $decider] = restrictedArticle();
        $appeal = $this->actingAs($author)->postJson("/api/v1/me/moderation/decisions/{$decision->uuid}/appeal", ['statement' => APPEAL_TEXT])->json('data.uuid');

        asModerator($decider)->postJson("/api/v1/admin/moderation/appeals/{$appeal}/decide", ['upheld' => true, 'reason' => 'Le lien a bien été retiré.'])->assertForbidden();
        expect(securityLog('moderation.appeal_self_review_denied'))->toHaveCount(1);

        asModerator()->postJson("/api/v1/admin/moderation/appeals/{$appeal}/decide", ['upheld' => true, 'reason' => 'Le lien a bien été retiré.'])->assertOk();
    });
});

describe('recours du signalant', function (): void {
    it('rouvre le signalement quand le recours d\'un membre est accepté', function (): void {
        [$member, $notice, $decision] = dismissedMemberNotice();

        $appeal = $this->actingAs($member)->postJson("/api/v1/me/moderation/notices/{$notice->uuid}/appeal", [
            'statement' => 'Le deuxième paragraphe promet explicitement un rendement garanti de 20 % par mois.',
        ])->assertCreated()->json('data.uuid');

        asModerator()->postJson("/api/v1/admin/moderation/appeals/{$appeal}/decide", ['upheld' => true, 'reason' => 'Le passage cité doit être réexaminé.'])->assertOk();

        $notice->refresh();
        expect($notice->status)->toBe(NoticeStatus::Open)
            ->and($notice->decision_id)->toBeNull()
            ->and($notice->active_key)->not->toBeNull();
        asModerator()->getJson('/api/v1/admin/moderation/queue')->assertJsonPath('meta.counts.open_notices', 1);
        Notification::assertSentTo($member, ModerationNotification::class, fn (ModerationNotification $n): bool => $n->mail === ModerationMail::AppealDecided
            && str_contains(implode(' ', $n->toMail($member)->introLines), 'examiné à nouveau'));
    });

    it('accepte le recours d\'un signalant sans compte avec son jeton, 404 sinon', function (): void {
        $slug = (string) articleOf(articleAuthor(), 'published')->slug;
        $this->postJson('/api/v1/moderation/notices', [
            'target_type' => 'article', 'target_ref' => $slug, 'category' => 'scam_fraud',
            'explanation' => 'Cet article promet des gains garantis à ses lecteurs.',
            'name' => 'Alex Martin', 'email' => 'alex@example.org', 'good_faith' => true,
        ])->assertCreated();
        $notice = Notice::query()->sole();

        asModerator()->postJson("/api/v1/admin/moderation/targets/article/{$slug}/decisions", [
            'measure' => 'no_action',
            'facts' => 'L\'article décrit un placement sans promettre de gain garanti.',
            'version' => ModerationTargets::get('article')->inspect($slug)?->version,
        ])->assertCreated();
        $this->app['auth']->forgetGuards();

        $token = null;
        Notification::assertSentOnDemand(ModerationNotification::class, function (ModerationNotification $n, array $channels, AnonymousNotifiable $to) use (&$token): bool {
            if ($n->mail !== ModerationMail::NoticeDecided) {
                return false;
            }
            preg_match('/token=([A-Za-z0-9]{48})/', (string) $n->toMail($to)->actionUrl, $match);
            $token = $match[1];

            return true;
        });

        $statement = ['statement' => 'Le deuxième paragraphe promet explicitement un rendement garanti.'];
        $this->postJson("/api/v1/moderation/notices/{$notice->uuid}/appeal", [...$statement, 'token' => str_repeat('a', 48)])->assertNotFound();
        $this->postJson("/api/v1/moderation/notices/{$notice->uuid}/appeal", $statement)->assertUnprocessable()->assertJsonValidationErrors(['token']);
        $this->postJson("/api/v1/moderation/notices/{$notice->uuid}/appeal", [...$statement, 'token' => $token])
            ->assertCreated();
        $this->postJson("/api/v1/moderation/notices/{$notice->uuid}/lookup", ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.decision.appeal.pending', true);
    });

    it('refuse un recours avant la décision ou contre une restriction (409)', function (): void {
        $member = projectOwner('particulier');
        $slug = (string) articleOf(articleAuthor(), 'published')->slug;
        $this->actingAs($member)->postJson('/api/v1/moderation/notices', [
            'target_type' => 'article', 'target_ref' => $slug, 'category' => 'scam_fraud',
            'explanation' => 'Cet article promet des gains garantis à ses lecteurs.', 'good_faith' => true,
        ])->assertCreated();
        $notice = Notice::query()->sole();
        $statement = ['statement' => 'Le deuxième paragraphe promet explicitement un rendement garanti.'];

        $this->actingAs($member)->postJson("/api/v1/me/moderation/notices/{$notice->uuid}/appeal", $statement)->assertConflict();

        asModerator()->postJson("/api/v1/admin/moderation/targets/article/{$slug}/decisions", [
            'measure' => 'restrict_visibility', 'ground' => 'terms', 'category' => 'scam_fraud', 'terms_clause' => 'prohibited_content',
            'facts' => 'L\'article promet des gains garantis, ce que les CGU interdisent.',
            'version' => ModerationTargets::get('article')->inspect($slug)?->version,
        ])->assertCreated();

        $this->actingAs($member)->postJson("/api/v1/me/moderation/notices/{$notice->uuid}/appeal", $statement)->assertConflict();
        $this->actingAs(projectOwner('particulier'))->postJson("/api/v1/me/moderation/notices/{$notice->uuid}/appeal", $statement)->assertNotFound();
    });

    it('limite les recours (429)', function (): void {
        config(['security.rate_limits.moderation_appeals.per_day' => 1]);
        [$member, $notice] = dismissedMemberNotice();
        $statement = ['statement' => 'Le deuxième paragraphe promet explicitement un rendement garanti.'];

        $this->actingAs($member)->postJson("/api/v1/me/moderation/notices/{$notice->uuid}/appeal", $statement)->assertCreated();
        $this->actingAs($member)->postJson("/api/v1/me/moderation/notices/{$notice->uuid}/appeal", $statement)->assertTooManyRequests();
    });
});
