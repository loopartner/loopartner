<?php

use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Notifications\MatchingNotification;
use App\Modules\Directory\Notifications\MatchRequestAcceptedNotification;
use App\Modules\Directory\Notifications\MatchRequestDeclinedNotification;
use App\Modules\Directory\Notifications\MatchRequestReceivedNotification;
use App\Modules\Directory\Notifications\NewMessageNotification;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Emails de la mise en relation : file d'attente, langue du destinataire, aucun contenu de
| message, aucun nom, aucun titre de projet d'autrui ; au plus un email "nouveau message" par
| conversation et par destinataire toutes les 15 minutes.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    Notification::fake();
    config(['app.frontend_url' => 'https://app.loopartner.test']);
});

/**
 * Tout le texte visible d'un email.
 */
function mailText(MailMessage $mail): string
{
    return implode("\n", [$mail->subject, $mail->greeting, ...$mail->introLines, $mail->actionText, $mail->actionUrl, ...$mail->outroLines, $mail->salutation]);
}

/**
 * Données personnelles qui ne doivent jamais apparaître dans un email.
 *
 * @return list<string>
 */
function personalData(User $owner, User $provider): array
{
    $profile = ProviderProfile::query()->where('user_id', $provider->id)->sole();

    return [$owner->name, $owner->email, $provider->name, $provider->email, $profile->display_name];
}

it('envoie chaque email par la file d\'attente, après la validation de la transaction', function (string $class): void {
    expect(is_subclass_of($class, ShouldQueueAfterCommit::class))->toBeTrue()
        ->and(is_subclass_of($class, MatchingNotification::class))->toBeTrue();
})->with([
    MatchRequestReceivedNotification::class,
    MatchRequestAcceptedNotification::class,
    MatchRequestDeclinedNotification::class,
    NewMessageNotification::class,
]);

describe('nouvelle demande reçue', function (): void {
    it('cite au porteur de projet le titre de SON projet, sans nom ni message', function (string $locale, string $subject): void {
        $owner = projectOwner('particulier');
        $owner->forceFill(['locale' => $locale])->save();
        $provider = browsingProvider();
        $project = publishedProjectOf($owner);
        $matchRequest = pendingProposal($provider, $project);

        Notification::assertSentTo($owner, MatchRequestReceivedNotification::class, function (MatchRequestReceivedNotification $notification) use ($owner, $provider, $project, $matchRequest, $locale, $subject): bool {
            $mail = $notification->toMail($owner);
            $text = mailText($mail);

            expect($notification->locale)->toBe($locale)
                ->and($mail->subject)->toBe($subject)
                ->and($text)->toContain($project->title)
                ->and($text)->not->toContain($matchRequest->message)
                ->and($mail->actionUrl)->toBe("https://app.loopartner.test/requests/{$matchRequest->uuid}");

            foreach (personalData($owner, $provider) as $value) {
                expect($text)->not->toContain($value);
            }

            return true;
        });
    })->with([
        'français' => ['fr', 'Nouvelle proposition pour votre projet'],
        'anglais' => ['en', 'New proposal for your project'],
    ]);

    it('prévient le prestataire contacté sans nom, sans message ni titre du projet rattaché', function (): void {
        $author = projectOwner('entrepreneur');
        $project = publishedProjectOf($author);
        $provider = browsingProvider();
        $message = 'Bonjour, votre profil correspond à ce que je recherche pour mon activité.';

        $this->actingAs($author)->postJson('/api/v1/providers/'.providerSlug($provider).'/contact', [
            'message' => $message,
            'project' => $project->uuid,
        ])->assertCreated();

        Notification::assertSentTo($provider, MatchRequestReceivedNotification::class, function (MatchRequestReceivedNotification $notification) use ($provider, $author, $project, $message): bool {
            $text = mailText($notification->toMail($provider));

            expect($text)->toContain('Nouvelle demande de contact')
                ->and($text)->not->toContain($project->title)
                ->and($text)->not->toContain($message);

            foreach (personalData($author, $provider) as $value) {
                expect($text)->not->toContain($value);
            }

            return true;
        });
    });
});

it('prévient l\'auteur de l\'acceptation et du refus, sans nom, message ni titre de projet', function (string $action, string $class, string $path): void {
    $owner = projectOwner('particulier');
    $provider = browsingProvider();
    $provider->forceFill(['locale' => 'en'])->save();
    $project = publishedProjectOf($owner);
    $matchRequest = pendingProposal($provider, $project);

    $this->actingAs($owner)->postJson("/api/v1/me/match-requests/{$matchRequest->uuid}/{$action}")->assertOk();

    Notification::assertSentTo($provider, $class, function (MatchingNotification $notification) use ($owner, $provider, $project, $matchRequest, $path): bool {
        $mail = $notification->toMail($provider);
        $text = mailText($mail);
        $expectedPath = $path === 'conversation' ? '/conversations/'.Conversation::query()->sole()->uuid : "/requests/{$matchRequest->uuid}";

        expect($notification->locale)->toBe('en')
            ->and($mail->actionUrl)->toBe('https://app.loopartner.test'.$expectedPath)
            ->and($text)->not->toContain($project->title)
            ->and($text)->not->toContain($matchRequest->message);

        foreach (personalData($owner, $provider) as $value) {
            expect($text)->not->toContain($value);
        }

        return true;
    });
})->with([
    'acceptation' => ['accept', MatchRequestAcceptedNotification::class, 'conversation'],
    'refus' => ['decline', MatchRequestDeclinedNotification::class, 'request'],
]);

describe('nouveau message', function (): void {
    it('prévient l\'autre participant sans le contenu du message, ni nom, ni titre', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $body = 'Voici mon numéro pour avancer plus vite : marqueur-confidentiel-42.';

        $this->actingAs($provider)->postJson("/api/v1/me/conversations/{$conversation->uuid}/messages", ['body' => $body])->assertCreated();

        Notification::assertSentTo($owner, NewMessageNotification::class, function (NewMessageNotification $notification) use ($owner, $provider, $conversation, $body): bool {
            $mail = $notification->toMail($owner);
            $text = mailText($mail);

            expect($text)->not->toContain($body)
                ->and($text)->not->toContain('marqueur-confidentiel-42')
                ->and($text)->not->toContain($conversation->matchRequest->project->title)
                ->and($mail->actionUrl)->toBe("https://app.loopartner.test/conversations/{$conversation->uuid}");

            foreach (personalData($owner, $provider) as $value) {
                expect($text)->not->toContain($value);
            }

            return true;
        });
        Notification::assertNotSentTo($provider, NewMessageNotification::class);
    });

    it('n\'envoie au plus qu\'un email toutes les 15 minutes par conversation et par destinataire', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();

        postMessage($provider, $conversation);
        Carbon::setTestNow(now()->addMinutes(5));
        postMessage($provider, $conversation);
        Carbon::setTestNow(now()->addMinutes(9));
        postMessage($provider, $conversation);
        Notification::assertSentToTimes($owner, NewMessageNotification::class, 1);

        Carbon::setTestNow(now()->addMinute());
        postMessage($provider, $conversation);
        Notification::assertSentToTimes($owner, NewMessageNotification::class, 2);
    });

    it('compte le délai séparément pour chaque destinataire et chaque conversation', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        [, , $other] = acceptedConversation($owner);

        postMessage($provider, $conversation);
        postMessage($owner, $conversation);
        postMessage($other->participants->firstWhere('user_id', '!=', $owner->id)->user, $other);

        Notification::assertSentToTimes($owner, NewMessageNotification::class, 2);
        Notification::assertSentToTimes($provider, NewMessageNotification::class, 1);
    });

    it('abandonne l\'email si le message a été lu avant le traitement de la file d\'attente', function (): void {
        [$owner, $provider, $conversation] = acceptedConversation();
        $message = postMessage($provider, $conversation);
        $notification = new NewMessageNotification($message);

        expect($notification->shouldSend($owner, 'mail'))->toBeTrue();

        $this->actingAs($owner)->postJson("/api/v1/me/conversations/{$conversation->uuid}/read")->assertNoContent();

        expect($notification->shouldSend($owner, 'mail'))->toBeFalse();
    });

    it('ne met dans la file d\'attente que l\'identifiant du message, jamais son contenu', function (): void {
        [, $provider, $conversation] = acceptedConversation();
        $message = postMessage($provider, $conversation, 'marqueur-confidentiel-dans-la-file');

        $payload = serialize(new NewMessageNotification($message));

        expect($payload)->not->toContain('marqueur-confidentiel-dans-la-file')
            ->and($payload)->toContain('ModelIdentifier');
    });
});
