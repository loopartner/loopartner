<?php

namespace App\Modules\Directory\Console\Commands;

use App\Modules\Directory\Actions\CloseExpiredProjects;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Clôture les projets expirés. Planifiée chaque jour dans routes/console.php.
 */
#[Signature('projects:close-expired')]
#[Description('Clôture les projets publiés dont la date d\'expiration est passée')]
class CloseExpiredProjectsCommand extends Command
{
    public function handle(CloseExpiredProjects $closeExpiredProjects): int
    {
        $closed = $closeExpiredProjects->handle();

        $this->info("{$closed} projet(s) expiré(s) clôturé(s).");

        return self::SUCCESS;
    }
}
