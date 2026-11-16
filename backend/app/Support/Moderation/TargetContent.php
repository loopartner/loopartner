<?php

namespace App\Support\Moderation;

/**
 * Contenu signalable tel que le module qui le possède le présente à la modération
 * (App\Support\Moderation\ModerationTarget). Données simples : jamais un modèle Eloquent d'un
 * module, pour respecter la règle d'isolation.
 *
 * - ref : identifiant public du contenu (slug ou uuid), jamais l'id interne ;
 * - ownerId : utilisateur qui a fourni le contenu (null si son compte est supprimé) ;
 * - label : titre ou nom, court ; text : copie textuelle du contenu (aucune image) ;
 * - version : empreinte du contenu lu par le modérateur (contrôle de version, 409 sinon) ;
 * - restrictedBy : uuid de la décision qui masque le contenu, null s'il est visible ;
 * - images : URL publiques des images actuellement en ligne (jamais copiées) ;
 * - publicPath : chemin de la page du contenu sur le frontend, s'il en a une.
 */
final readonly class TargetContent
{
    /**
     * @param  list<string>  $images
     */
    public function __construct(
        public string $type,
        public string $ref,
        public ?int $ownerId,
        public string $label,
        public string $text,
        public string $version,
        public ?string $restrictedBy,
        public array $images = [],
        public ?string $publicPath = null,
    ) {}

    public function isRestricted(): bool
    {
        return $this->restrictedBy !== null;
    }

    /**
     * Empreinte stable d'un ensemble de champs, pour le contrôle de version d'un contenu qui
     * n'a pas de colonne de version (profil, projet, message…).
     *
     * @param  array<string, mixed>  $fields
     */
    public static function fingerprint(array $fields): string
    {
        return hash('sha256', (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Assemble des champs texte en une copie lisible (champs vides ignorés).
     *
     * @param  array<string, string|null>  $fields  libellé => valeur
     */
    public static function joinText(array $fields): string
    {
        $lines = [];

        foreach ($fields as $label => $value) {
            if ($value !== null && trim($value) !== '') {
                $lines[] = $label.' : '.trim($value);
            }
        }

        return implode("\n", $lines);
    }
}
