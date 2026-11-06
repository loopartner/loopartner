<?php

/*
| Règle d'isolation : un module n'utilise jamais directement les modèles d'un
| autre module. App\Models\User, modèle partagé du projet, reste autorisé partout.
*/

const MODULES = ['Accounts', 'Directory', 'Events', 'Blog', 'Compliance'];

foreach (MODULES as $module) {
    $otherModulesModels = array_map(
        fn (string $other): string => "App\\Modules\\{$other}\\Models",
        array_values(array_diff(MODULES, [$module])),
    );

    arch("le module {$module} n'utilise pas les modèles des autres modules")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($otherModulesModels);
}

it('teste la règle d\'isolation pour chaque module présent dans app/Modules', function (): void {
    $directories = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR) ?: []);

    expect($directories)->toEqualCanonicalizing(MODULES);
});

arch('le code applicatif ne contient pas de fonctions de débogage')
    ->preset()
    ->php();

arch('le code applicatif ne contient pas de fonctions dangereuses')
    ->preset()
    ->security();
