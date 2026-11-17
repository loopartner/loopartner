<?php

use App\Models\NotificationPreference;
use App\Models\User;
use App\Modules\Blog\Notifications\ModerationDigestNotification;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Notifications\EventNotification;
use App\Modules\Marketplace\Enums\QuoteNotice;
use App\Modules\Marketplace\Notifications\QuoteNotification;
use App\Modules\Moderation\Notifications\ModerationNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\Notifications\CategorizedNotification;
use App\Support\Notifications\NotificationCategories;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;

/*
| Brique de notifications du noyau : catégorie de chaque notification, préférences de
| l'utilisateur (GET|PUT /me/notification-preferences), envoi abandonné pour une catégorie
| facultative désactivée.
*/

function notifiedUser(string $role = 'entrepreneur'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Notifications réellement envoyées pendant le test (sans Notification::fake(), qui ne
 * déclenche pas les écouteurs).
 *
 * @return list<array{notification: class-string, channel: string, user: int}>
 */
function sentNotifications(): array
{
    return app('test.sent_notifications')->all();
}

beforeEach(function (): void {
    $sent = collect();
    app()->instance('test.sent_notifications', $sent);
    Event::listen(NotificationSent::class, function (NotificationSent $event) use ($sent): void {
        $sent->push(['notification' => $event->notification::class, 'channel' => $event->channel, 'user' => $event->notifiable->id]);
    });
});

/**
 * @return list<class-string<Notification>>
 */
function applicationNotificationClasses(): array
{
    $classes = [];
    $paths = [...(glob(app_path('Notifications/*.php')) ?: []), ...(glob(app_path('Modules/*/Notifications/*.php')) ?: [])];

    foreach ($paths as $path) {
        $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '/', '.php'], ['', '\\', ''], $path);
        $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        if (is_subclass_of($class, Notification::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

describe('catégories', function (): void {
    it('rattache chaque notification de l\'application à une catégorie déclarée', function (): void {
        $classes = applicationNotificationClasses();

        expect(count($classes))->toBeGreaterThan(10);

        foreach ($classes as $class) {
            expect(is_subclass_of($class, CategorizedNotification::class))->toBeTrue("{$class} n'a pas de catégorie");

            $reflection = new ReflectionClass($class);

            // Catégorie fixée à la construction (destinataire) : vérifiée à part ci-dessous.
            if ($reflection->isAbstract() || in_array($class, [EventNotification::class, ModerationNotification::class, QuoteNotification::class], true)) {
                continue;
            }

            /** @var CategorizedNotification $instance */
            $instance = $reflection->newInstanceWithoutConstructor();
            expect(NotificationCategories::exists($instance->notificationCategory()))->toBeTrue($class);
        }

        foreach (EventNotice::cases() as $notice) {
            expect(NotificationCategories::exists($notice->notificationCategory()))->toBeTrue($notice->value);
        }

        foreach (['moderation.notices', 'moderation.decisions'] as $category) {
            expect(NotificationCategories::isMandatory($category))->toBeTrue($category);
        }

        foreach (QuoteNotice::cases() as $notice) {
            expect(NotificationCategories::exists($notice->category()))->toBeTrue($notice->value);
        }

        expect(NotificationCategories::isMandatory('marketplace.quote_decisions'))->toBeTrue()
            ->and(NotificationCategories::isMandatory('marketplace.quotes'))->toBeFalse();
    });

    it('garde obligatoires la sécurité du compte, les inscriptions et les décisions de modération', function (): void {
        foreach (['account.security', 'events.participation', 'events.organizer', 'blog.author'] as $category) {
            expect(NotificationCategories::isMandatory($category))->toBeTrue($category);
        }

        expect(EventNotice::Cancelled->notificationCategory())->toBe('events.participation')
            ->and(EventNotice::Reminder->notificationCategory())->toBe('events.reminders')
            ->and(EventNotice::AnnouncementPublished->notificationCategory())->toBe('events.announcements')
            ->and(EventNotice::EventRejected->notificationCategory())->toBe('events.organizer');
    });
});

describe('envoi', function (): void {
    it('envoie par défaut une catégorie facultative', function (): void {
        $moderator = notifiedUser('redacteur');

        $moderator->notify(new ModerationDigestNotification(['article' => 1, 'revision' => 0]));

        expect(sentNotifications())->toHaveCount(1);
    });

    it('abandonne une catégorie facultative désactivée, pour cet utilisateur seulement', function (): void {
        [$optedOut, $other] = [notifiedUser('redacteur'), notifiedUser('redacteur')];
        NotificationPreference::query()->forceCreate(['user_id' => $optedOut->id, 'category' => 'blog.moderation_digest', 'channel' => 'mail', 'enabled' => false]);

        $optedOut->notify(new ModerationDigestNotification(['article' => 1, 'revision' => 0]));
        $other->notify(new ModerationDigestNotification(['article' => 1, 'revision' => 0]));

        expect(array_column(sentNotifications(), 'user'))->toBe([$other->id]);
    });

    it('envoie toujours une catégorie obligatoire, même désactivée en base', function (): void {
        $user = notifiedUser();
        NotificationPreference::query()->forceCreate(['user_id' => $user->id, 'category' => 'account.security', 'channel' => 'mail', 'enabled' => false]);

        $user->notify(new VerifyEmailNotification);

        expect(sentNotifications())->toHaveCount(1);
    });
});

describe('GET|PUT /me/notification-preferences', function (): void {
    it('refuse un visiteur anonyme (401)', function (): void {
        $this->getJson('/api/v1/me/notification-preferences')->assertUnauthorized();
        $this->putJson('/api/v1/me/notification-preferences', ['preferences' => []])->assertUnauthorized();
    });

    it('liste les catégories du rôle, activées par défaut, avec leur libellé traduit', function (): void {
        $response = $this->actingAs(notifiedUser('particulier'))
            ->getJson('/api/v1/me/notification-preferences?locale=en')
            ->assertOk();

        expect(array_column($response->json('data'), 'category'))->toBe([
            'account.security', 'directory.match_requests', 'directory.messages',
            'marketplace.quotes', 'marketplace.quote_decisions', 'events.participation', 'events.reminders', 'events.announcements',
            'moderation.notices', 'moderation.decisions',
        ]);
        $response->assertJsonPath('data.6', [
            'category' => 'events.reminders',
            'label' => 'Reminder the day before an event',
            'mandatory' => false,
            'channels' => ['mail' => true],
        ]);
    });

    it('enregistre un choix, le modifie, et le renvoie', function (): void {
        $user = notifiedUser();

        $this->actingAs($user)->putJson('/api/v1/me/notification-preferences', ['preferences' => [
            ['category' => 'events.reminders', 'channel' => 'mail', 'enabled' => false],
            ['category' => 'compliance.reminders', 'channel' => 'mail', 'enabled' => false],
        ]])->assertOk()->assertJsonFragment(['category' => 'events.reminders', 'label' => 'Rappel la veille d\'un événement', 'mandatory' => false, 'channels' => ['mail' => false]]);

        $this->actingAs($user)->putJson('/api/v1/me/notification-preferences', ['preferences' => [
            ['category' => 'events.reminders', 'channel' => 'mail', 'enabled' => true],
        ]])->assertOk();

        expect(NotificationPreference::query()->where('user_id', $user->id)->orderBy('category')->get(['category', 'enabled'])->toArray())->toBe([
            ['category' => 'compliance.reminders', 'enabled' => false],
            ['category' => 'events.reminders', 'enabled' => true],
        ]);
    });

    it('refuse de désactiver une catégorie obligatoire (422)', function (): void {
        $user = notifiedUser();

        $this->actingAs($user)->putJson('/api/v1/me/notification-preferences', ['preferences' => [
            ['category' => 'account.security', 'channel' => 'mail', 'enabled' => false],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['preferences.0.category']);

        expect(NotificationPreference::query()->count())->toBe(0);
    });

    it('refuse les entrées invalides (422)', function (array $preferences, string $field): void {
        $this->actingAs(notifiedUser('particulier'))
            ->putJson('/api/v1/me/notification-preferences', ['preferences' => $preferences])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(NotificationPreference::query()->count())->toBe(0);
    })->with([
        'catégorie inconnue' => [[['category' => 'marketing.newsletter', 'channel' => 'mail', 'enabled' => false]], 'preferences.0.category'],
        'catégorie d\'un autre rôle' => [[['category' => 'blog.moderation_digest', 'channel' => 'mail', 'enabled' => false]], 'preferences.0.category'],
        'canal inconnu' => [[['category' => 'events.reminders', 'channel' => 'sms', 'enabled' => false]], 'preferences.0.channel'],
        'booléen en texte' => [[['category' => 'events.reminders', 'channel' => 'mail', 'enabled' => 'false']], 'preferences.0.enabled'],
        'clé en trop' => [[['category' => 'events.reminders', 'channel' => 'mail', 'enabled' => false, 'user_id' => 1]], 'preferences.0'],
        'doublon' => [[['category' => 'events.reminders', 'channel' => 'mail', 'enabled' => false], ['category' => 'events.reminders', 'channel' => 'mail', 'enabled' => true]], 'preferences'],
        'liste vide' => [[], 'preferences'],
    ]);

    it('ne modifie jamais les choix d\'un autre utilisateur', function (): void {
        [$user, $other] = [notifiedUser(), notifiedUser()];
        NotificationPreference::query()->forceCreate(['user_id' => $other->id, 'category' => 'events.reminders', 'channel' => 'mail', 'enabled' => false]);

        $this->actingAs($user)->putJson('/api/v1/me/notification-preferences', ['preferences' => [
            ['category' => 'events.reminders', 'channel' => 'mail', 'enabled' => true],
        ]])->assertOk();

        expect(NotificationPreference::query()->where('user_id', $other->id)->value('enabled'))->toBeFalse();
        expectNoPrivateFields($this->actingAs($user)->getJson('/api/v1/me/notification-preferences')->json());
    });

    it('supprime les préférences avec le compte', function (): void {
        $user = notifiedUser();
        NotificationPreference::query()->forceCreate(['user_id' => $user->id, 'category' => 'events.reminders', 'channel' => 'mail', 'enabled' => false]);

        $user->delete();

        expect(NotificationPreference::query()->count())->toBe(0);
    });
});
