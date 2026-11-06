<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Rôles de la plateforme.
     *
     * @var list<string>
     */
    public const ROLES = [
        'particulier',
        'prestataire',
        'entrepreneur',
        'redacteur',
        'admin',
    ];

    /**
     * Crée les rôles s'ils n'existent pas encore. Le seeder est idempotent.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
