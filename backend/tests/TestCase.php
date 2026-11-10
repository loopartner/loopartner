<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les requêtes de test reçoivent par défaut "Accept-Language: en-us" (valeur de Symfony
     * pour les requêtes synthétiques). On envoie plutôt l'en-tête d'un navigateur français ;
     * chaque test peut le remplacer.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9');
    }
}
