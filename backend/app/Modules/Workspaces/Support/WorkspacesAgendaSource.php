<?php

namespace App\Modules\Workspaces\Support;

use App\Models\User;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Support\Agenda\AgendaItem;
use App\Support\Agenda\AgendaSource;
use Carbon\CarbonImmutable;

/**
 * Échéances des workspaces dans l'agenda unifié et l'abonnement (décision 67) : étapes et
 * tâches datées des workspaces **modifiables** de l'utilisateur (ni archivés ni verrouillés),
 * en journée entière. Titre : celui de l'étape ou de la tâche, précédé de « ✓ » une fois
 * faite ; jamais de note ni de description.
 */
class WorkspacesAgendaSource implements AgendaSource
{
    private const LIMIT = 500;

    public function __construct(private Workspaces $workspaces) {}

    public function items(User $user, CarbonImmutable $from, CarbonImmutable $to, string $locale, bool $forFeed): array
    {
        $workspaceIds = $this->workspaces->writableIds($user->id);

        if ($workspaceIds === []) {
            return [];
        }

        $range = fn ($query) => $query
            ->whereIn('workspace_id', $workspaceIds)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $from->toDateString())
            ->whereDate('due_date', '<', $to->toDateString())
            ->orderBy('due_date')
            ->limit(self::LIMIT);

        $steps = $range(Step::query())->get()->map(fn (Step $step): AgendaItem => $this->item('workspace_step', $step->uuid, $step->title, $step->due_date, $step->completed_at !== null));
        $tasks = $range(Task::query())->get()->map(fn (Task $task): AgendaItem => $this->item('workspace_task', $task->uuid, $task->title, $task->due_date, $task->completed_at !== null));

        return $steps->concat($tasks)->values()->all();
    }

    public function disclaimer(string $locale): ?array
    {
        return null;
    }

    private function item(string $kind, string $ref, string $title, mixed $dueDate, bool $done): AgendaItem
    {
        $day = CarbonImmutable::parse((string) $dueDate?->toDateString(), 'UTC');

        return new AgendaItem(
            source: 'workspaces',
            kind: $kind,
            ref: $ref,
            title: $done ? '✓ '.$title : $title,
            startsAt: $day,
            endsAt: $day->addDay(),
            allDay: true,
            timezone: null,
            status: $done ? 'done' : 'todo',
        );
    }
}
