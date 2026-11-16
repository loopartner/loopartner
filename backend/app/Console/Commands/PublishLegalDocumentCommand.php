<?php

namespace App\Console\Commands;

use App\Support\Legal\LegalDocuments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Publie une nouvelle version d'un document légal (CGU ou politique de confidentialité) à
 * partir d'un fichier JSON :
 *
 * {"type": "terms", "version": "2027-01", "effective_at": "2027-01-15T00:00:00Z",
 *  "title": {"fr": "…", "en": "…"}, "body": {"fr": "…", "en": "…"}}
 *
 * Texte brut (sans HTML). Une version publiée n'est jamais modifiée ; effective_at (facultatif,
 * maintenant par défaut) ne peut pas être passé. Dès son entrée en vigueur, chaque utilisateur
 * doit l'accepter avant de continuer (403 consent_required) : la publier à l'avance permet de
 * l'annoncer (GET /legal/documents, champ upcoming).
 */
#[Signature('legal:publish {file : Fichier JSON du document}')]
#[Description('Publie une nouvelle version des CGU ou de la politique de confidentialité')]
class PublishLegalDocumentCommand extends Command
{
    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Fichier introuvable : {$path}");

            return self::FAILURE;
        }

        $input = json_decode((string) file_get_contents($path), true);

        if (! is_array($input)) {
            $this->error('Le fichier n\'est pas un objet JSON valide.');

            return self::FAILURE;
        }

        try {
            $document = LegalDocuments::publish($input);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error("{$field} : ".implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Version %s du document « %s » publiée, en vigueur le %s (UTC). Empreinte : %s',
            $document->version,
            $document->type->value,
            $document->effective_at->format('Y-m-d H:i'),
            $document->content_hash,
        ));

        return self::SUCCESS;
    }
}
