<?php

/*
| Dépendances : "composer security" lance composer audit (vulnérabilités connues des paquets
| installés). À lancer avant chaque commit et chaque mise en production.
*/

it('déclare le script composer security qui lance composer audit', function (): void {
    /** @var array{scripts: array<string, string|list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts'])->toHaveKey('security')
        ->and((array) $composer['scripts']['security'])->toContain('@composer audit');
});
