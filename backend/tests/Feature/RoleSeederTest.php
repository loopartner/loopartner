<?php

use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

it('crée les rôles de la plateforme sans doublon', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Role::query()->where('guard_name', 'web')->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'entrepreneur', 'particulier', 'prestataire', 'redacteur']);
});
