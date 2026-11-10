<?php

namespace App\Console\Commands;

use App\Support\Security\ConfigAudit;
use App\Support\Security\ConfigFinding;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Vérifie la configuration de sécurité de production. À lancer à chaque déploiement, après
 * php artisan config:cache : la commande échoue (code 1) si une valeur est dangereuse.
 */
#[Signature('security:check-config')]
#[Description('Vérifie que la configuration est sûre pour la production (échoue sur toute valeur dangereuse)')]
class CheckSecurityConfig extends Command
{
    public function handle(ConfigAudit $audit): int
    {
        $findings = $audit->run();

        if ($findings === []) {
            $this->info('Aucun problème de configuration détecté.');

            return self::SUCCESS;
        }

        $this->table(
            ['Niveau', 'Paramètre', 'Problème'],
            array_map(fn (ConfigFinding $finding): array => [$finding->level, $finding->variable, $finding->message], $findings),
        );

        $errors = count(array_filter($findings, fn (ConfigFinding $finding): bool => $finding->isError()));

        if ($errors > 0) {
            $this->error("{$errors} erreur(s) : configuration dangereuse pour la production. Corrigez-la avant de déployer.");

            return self::FAILURE;
        }

        $this->warn('Aucun problème bloquant, mais des avertissements sont à vérifier.');

        return self::SUCCESS;
    }
}
