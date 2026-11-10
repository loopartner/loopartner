<?php

use App\Models\Media;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

it('supprime les médias jamais rattachés de plus de 24 heures, avec leurs fichiers', function (): void {
    $old = Media::factory()->withFile()->create(['created_at' => now()->subHours(25)]);
    $recent = Media::factory()->withFile()->create(['created_at' => now()->subHours(23)]);
    $attached = Media::factory()->attached()->withFile()->create(['created_at' => now()->subDays(10)]);

    $this->artisan('media:prune-orphans')
        ->expectsOutputToContain('1 média(s) orphelin(s) supprimé(s).')
        ->assertSuccessful();

    $this->assertModelMissing($old);
    Storage::disk('public')->assertMissing($old->path);

    $this->assertModelExists($recent);
    Storage::disk('public')->assertExists($recent->path);

    $this->assertModelExists($attached);
    Storage::disk('public')->assertExists($attached->path);
});

it('accepte un délai personnalisé', function (): void {
    $media = Media::factory()->withFile()->create(['created_at' => now()->subHours(3)]);

    $this->artisan('media:prune-orphans', ['--hours' => 2])->assertSuccessful();

    $this->assertModelMissing($media);
    Storage::disk('public')->assertMissing($media->path);
});

it('refuse un délai inférieur à une heure', function (): void {
    $media = Media::factory()->create(['created_at' => now()->subHours(3)]);

    $this->artisan('media:prune-orphans', ['--hours' => 0])->assertFailed();

    $this->assertModelExists($media);
});

it('est planifiée chaque jour, sans chevauchement', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'media:prune-orphans'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
