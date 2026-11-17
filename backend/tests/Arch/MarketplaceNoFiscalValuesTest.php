<?php

/*
| Module Marketplace : aucun taux, seuil ni montant réglementaire dans le code (décision 49).
| Les taux de TVA viennent des règles vérifiées du module Compliance (GetVatRates) ; les
| montants sont en centimes et les taux en points de base, saisis ou lus, jamais écrits ici.
|
| Garde-fou par lecture du code du module et de sa configuration : aucun nombre décimal, et
| aucun entier au-delà de 10 000 (échelle des points de base), sauf les plafonds techniques
| de saisie listés ci-dessous.
*/

/**
 * Plafonds techniques : prix unitaire, quantité et total HT d'un devis (calcul sans
 * dépassement de capacité), capital social saisi, numéro de devis annuel, taille du corps
 * d'une requête de devis, taille de la copie figée du logo.
 */
const MARKETPLACE_ALLOWED_LARGE_INTEGERS = [1_000_000_000, 10_000_000, 100_000_000_000, 10_000_000_000_000, 99_999, 131_072, 262_144];

it('n\'écrit aucune valeur fiscale (décimal ou grand nombre) dans le code du module Marketplace', function (): void {
    $root = dirname(__DIR__, 2);
    $files = [
        $root.'/config/marketplace.php',
        ...iterator_to_array(new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Modules/Marketplace')),
            '/\.php$/',
        ), false),
    ];

    $violations = [];

    foreach ($files as $file) {
        foreach (token_get_all((string) file_get_contents((string) $file)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            $value = str_replace('_', '', $token[1]);
            $tooLarge = $token[0] === T_LNUMBER && (int) $value > 10_000 && ! in_array((int) $value, MARKETPLACE_ALLOWED_LARGE_INTEGERS, true);

            if ($token[0] === T_DNUMBER || $tooLarge) {
                $violations[] = basename((string) $file).':'.$token[2].' ('.$token[1].')';
            }
        }
    }

    expect($violations)->toBe([]);
});
