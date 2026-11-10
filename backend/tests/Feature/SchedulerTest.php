<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
| Production (Hostinger mutualisé) : une tâche cron lance "php artisan schedule:run" chaque
| minute, et aucun processus permanent n'est autorisé.
*/

function scheduledEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));
}

it('traite la file d\'attente chaque minute, sans worker permanent ni chevauchement', function (): void {
    $event = scheduledEvent('queue:work');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBeLessThan(60 * 24)
        ->and($event->command)->toContain('--stop-when-empty');

    preg_match('/--max-time=(\d+)/', (string) $event->command, $matches);

    expect($matches)->toHaveKey(1)
        ->and((int) $matches[1])->toBeGreaterThan(0)->toBeLessThan(60);
});
