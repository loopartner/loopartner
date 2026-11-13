<?php

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Support\CalendarGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recalcul du calendrier d'un lot d'entreprises après un changement de règles. Une entreprise
 * dont le calendrier a changé est marquée (calendar_changed_at) : le prochain récapitulatif par
 * email le signale à l'utilisateur.
 */
class RefreshCompanyCalendars implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  list<int>  $companyIds
     */
    public function __construct(public readonly array $companyIds) {}

    public function handle(): void
    {
        foreach (Company::query()->whereKey($this->companyIds)->get() as $company) {
            if (CalendarGenerator::refresh($company)) {
                $company->forceFill(['calendar_changed_at' => now()])->save();
            }
        }
    }
}
