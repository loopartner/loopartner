<?php

namespace App\Modules\Compliance\Console\Commands;

use App\Modules\Compliance\Support\RuleImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Importe un fichier de règles en BROUILLON (jamais vérifiées, jamais visibles des
 * utilisateurs) pour qu'un expert-comptable les relise dans l'administration :
 *
 *   php artisan compliance:import-rules database/data/compliance/fr-2027.draft.json [--dry-run]
 *
 * Utilisable en production. Une règle déjà présente (même code et même date de début) est
 * ignorée ; une seule règle invalide, et rien n'est importé.
 */
#[Signature('compliance:import-rules {path : Fichier JSON, relatif au dossier backend ou absolu} {--dry-run : Afficher ce qui serait importé, sans rien écrire}')]
#[Description('Importe des règles administratives en brouillon depuis un fichier JSON')]
class ImportRulesCommand extends Command
{
    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $path = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 ? $path : base_path($path);
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = RuleImporter::import($path, $dryRun);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['created'] as $code) {
            $this->line(($dryRun ? '[simulation] ' : '').'brouillon créé : '.$code);
        }

        foreach ($result['skipped'] as $code) {
            $this->line('déjà présente, ignorée : '.$code);
        }

        $this->info(sprintf(
            '%d brouillon(s) %s, %d règle(s) ignorée(s). Aucune règle n\'est visible des utilisateurs avant sa vérification.',
            count($result['created']),
            $dryRun ? 'à créer' : 'créé(s)',
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }
}
