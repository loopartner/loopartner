<?php

/*
| Module Compliance : les règles fiscales et sociales (seuils, taux, montants, dates) ne sont
| JAMAIS écrites dans le code, ce sont des données vérifiées (table compliance_rules).
|
| Garde-fou par lecture du code du module et de sa configuration :
| - aucun nombre décimal (un taux s'écrit en points de base, un montant en centimes, et ils
|   viennent des règles) ;
| - aucun entier au-delà de 10 000 (échelle des points de base), sauf les quelques constantes
|   techniques listées ci-dessous.
*/

/**
 * Constantes techniques autorisées : plafond de saisie des montants, secondes d'une journée.
 */
const COMPLIANCE_ALLOWED_LARGE_INTEGERS = [99_999_999_999, 86_400];

it('n\'écrit aucune valeur fiscale (décimal ou grand nombre) dans le code du module', function (): void {
    $root = dirname(__DIR__, 2);
    $files = [
        $root.'/config/compliance.php',
        ...iterator_to_array(new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Modules/Compliance')),
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
            $tooLarge = $token[0] === T_LNUMBER && (int) $value > 10_000 && ! in_array((int) $value, COMPLIANCE_ALLOWED_LARGE_INTEGERS, true);

            if ($token[0] === T_DNUMBER || $tooLarge) {
                $violations[] = basename((string) $file).':'.$token[2].' ('.$token[1].')';
            }
        }
    }

    expect($violations)->toBe([]);
});
