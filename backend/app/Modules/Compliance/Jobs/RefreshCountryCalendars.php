<?php

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;

/**
 * Une règle d'échéance d'un pays a été vérifiée ou retirée : recalcul du calendrier de toutes
 * les entreprises de ce pays, par lots (un job RefreshCompanyCalendars par lot), pour que
 * chaque job tienne dans le temps du worker (50 secondes sur l'hébergement mutualisé).
 */
class RefreshCountryCalendars implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BATCH = 200;

    public function __construct(public readonly string $country) {}

    public function handle(): void
    {
        Company::query()
            ->where('country', $this->country)
            ->select('id')
            ->chunkById(self::BATCH, function (Collection $companies): void {
                RefreshCompanyCalendars::dispatch($companies->pluck('id')->map(fn ($id): int => (int) $id)->values()->all());
            });
    }
}
