<?php

use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;

/*
| Échéances des workspaces dans l'agenda unifié et l'abonnement (décision 67) : étapes et
| tâches datées des workspaces modifiables, titres et dates seulement.
*/

it('ajoute les étapes et tâches datées à l\'agenda, sans note ni description', function (): void {
    $this->travelTo('2027-03-01 09:00:00');
    $user = workspaceOwner();
    $workspace = workspaceOf($user);
    $step = Step::factory()->for($workspace)->create(['title' => 'Choisir le graphiste', 'description' => 'Description privée', 'due_date' => '2027-03-10']);
    Task::factory()->for($step)->create(['title' => 'Relire le brief', 'notes' => 'Note privée', 'due_date' => '2027-03-05', 'completed_at' => now()]);
    Task::factory()->for($step)->create(['title' => 'Sans date', 'due_date' => null]);
    Task::factory()->for($step)->create(['title' => 'Trop tard', 'due_date' => '2027-06-01']);

    $response = $this->actingAs($user)->getJson('/api/v1/me/agenda?from=2027-03-01&to=2027-03-31&sources[]=workspaces')->assertOk();

    expect(collect($response->json('data'))->map(fn (array $item): array => [$item['kind'], $item['title'], $item['date'], $item['status']])->all())
        ->toBe([
            ['workspace_task', '✓ Relire le brief', '2027-03-05', 'done'],
            ['workspace_step', 'Choisir le graphiste', '2027-03-10', 'todo'],
        ])
        ->and($response->getContent())->not->toContain('privée');

    $url = $this->actingAs($user)->postJson('/api/v1/me/agenda/feed')->json('data.url');
    $ics = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->getContent();

    expect($ics)->toContain('Choisir le graphiste')->not->toContain('privée');
});

it('n\'inclut ni les workspaces archivés, ni les workspaces verrouillés, ni ceux des autres', function (): void {
    $this->travelTo('2027-03-01 09:00:00');
    $user = workspaceOwner();
    $archived = workspaceOf($user, ['status' => 'archived', 'archived_at' => now()]);
    Step::factory()->for($archived)->create(['title' => 'Archivée', 'due_date' => '2027-03-10']);

    // Deux workspaces actifs en gratuit (fin du Premium) : seul le plus récemment activé compte.
    workspaceOf($user, ['activated_at' => now()->subDays(2)]);
    $locked = workspaceOf($user, ['activated_at' => now()->subDays(3)]);
    Step::factory()->for($locked)->create(['title' => 'Verrouillée', 'due_date' => '2027-03-10']);

    Step::factory()->for(workspaceOf(workspaceOwner()))->create(['title' => 'Autre utilisateur', 'due_date' => '2027-03-10']);

    $this->actingAs($user)->getJson('/api/v1/me/agenda?from=2027-03-01&to=2027-03-31&sources[]=workspaces')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
