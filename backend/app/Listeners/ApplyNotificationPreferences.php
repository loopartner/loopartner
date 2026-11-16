<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Notifications\CategorizedNotification;
use App\Support\Notifications\NotificationCategories;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Abandonne l'envoi d'une notification dont l'utilisateur a désactivé la catégorie sur ce
 * canal (renvoyer false arrête l'envoi). Vérifié au moment de l'envoi, après la file
 * d'attente : un choix fait entre-temps s'applique. Une catégorie obligatoire part toujours.
 */
class ApplyNotificationPreferences
{
    public function handle(NotificationSending $event): ?bool
    {
        if (! $event->notification instanceof CategorizedNotification || ! $event->notifiable instanceof User) {
            return null;
        }

        $category = $event->notification->notificationCategory();

        return NotificationCategories::isDisabled($event->notifiable, $category, $event->channel) ? false : null;
    }
}
