<?php

namespace App\Support\Notifications;

/**
 * Notification rattachée à une catégorie de config/notifications.php : l'utilisateur peut
 * désactiver une catégorie facultative, et l'envoi est alors abandonné
 * (App\Listeners\ApplyNotificationPreferences).
 *
 * Toute notification de l'application implémente cette interface (vérifié par un test).
 */
interface CategorizedNotification
{
    public function notificationCategory(): string;
}
