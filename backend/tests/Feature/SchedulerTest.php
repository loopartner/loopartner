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

it('clôture les projets expirés chaque jour, sans chevauchement', function (): void {
    $event = scheduledEvent('projects:close-expired');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('fait expirer les demandes de mise en relation chaque jour, après la clôture des projets', function (): void {
    $event = scheduledEvent('match-requests:expire');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('purge les données de mise en relation arrivées au bout de leur conservation, chaque jour', function (): void {
    $event = scheduledEvent('matching:prune');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('prévient les admins des éléments à valider toutes les 5 minutes, sans chevauchement', function (): void {
    $event = scheduledEvent('events:notify-moderators');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('purge le journal d\'audit d\'administration chaque jour, sans chevauchement', function (): void {
    $event = scheduledEvent('admin-audit:prune');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('déclare le worker de file d\'attente après toutes les autres tâches', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn (Event $event): string => (string) $event->command);

    expect($commands->last())->toContain('queue:work')
        ->and($commands->filter(fn (string $command): bool => str_contains($command, 'queue:work')))->toHaveCount(1);
});

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
