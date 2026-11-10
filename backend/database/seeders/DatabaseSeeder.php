<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounts\Actions\UpsertProviderProfile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Mot de passe des comptes de test locaux (le même que celui de UserFactory).
     */
    public const LOCAL_PASSWORD = 'password';

    /**
     * Seed the application's database. Le seeder est idempotent : il peut être relancé.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        if (app()->isLocal()) {
            $this->seedLocalUsers();
        }
    }

    /**
     * Un utilisateur vérifié par rôle : particulier@example.com, prestataire@example.com, etc.
     */
    private function seedLocalUsers(): void
    {
        foreach (RoleSeeder::ROLES as $role) {
            $user = User::query()->firstOrCreate(
                ['email' => "{$role}@example.com"],
                [
                    'name' => ucfirst($role).' Test',
                    'password' => Hash::make(self::LOCAL_PASSWORD),
                ],
            );

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $user->syncRoles([$role]);

            if ($role === 'prestataire') {
                app(UpsertProviderProfile::class)->handle($user, [
                    'display_name' => 'Prestataire Test',
                    'headline' => 'Profil de démonstration',
                    'bio' => 'Profil prestataire créé par le seeder pour le développement local.',
                    'city' => 'Lyon',
                    'country' => 'FR',
                    'status' => 'published',
                ]);
            }
        }
    }
}
