<?php

namespace App\Modules\Compliance\Console\Commands;

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Support\CalendarGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Fait avancer la fenêtre du calendrier (compliance.calendar_horizon_months) : chaque jour, les
 * entreprises dont le calendrier n'a pas été recalculé depuis le début de la journée (UTC) le
 * sont. Idempotente ; planifiée chaque jour dans routes/console.php.
 */
#[Signature('compliance:extend-calendars')]
#[Description('Prolonge le calendrier des échéances de chaque entreprise')]
class ExtendCalendarsCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        Company::query()
            ->where(fn ($query) => $query->whereNull('calendar_refreshed_at')->orWhere('calendar_refreshed_at', '<', now()->startOfDay()))
            ->chunkById(100, function (Collection $companies) use (&$count): void {
                foreach ($companies as $company) {
                    /** @var Company $company */
                    CalendarGenerator::refresh($company);
                    $count++;
                }
            });

        $this->info(sprintf('%d calendrier(s) prolongé(s).', $count));

        return self::SUCCESS;
    }
}
