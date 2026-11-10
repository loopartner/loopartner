<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Données de référence, nécessaires dans tous les environnements : rôles et catégories de
 * services. C'est le seul seeder à lancer en production :
 *
 *   php artisan db:seed --class="Database\Seeders\ReferenceDataSeeder" --force
 *
 * Il est idempotent et ne crée jamais d'utilisateur.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RoleSeeder::class, ServiceCategorySeeder::class]);
    }
}
