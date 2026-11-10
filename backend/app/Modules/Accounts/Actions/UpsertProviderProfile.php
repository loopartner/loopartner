<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crée le profil prestataire d'un utilisateur s'il n'existe pas, sinon le met à jour.
 *
 * - Le slug est calculé à partir de display_name à la création uniquement, puis ne change
 *   plus : les URL publiques restent stables.
 * - La publication exige un email vérifié.
 * - published_at est fixé au passage en "published" et remis à null au retour en brouillon.
 */
class UpsertProviderProfile
{
    private const SLUG_MAX_LENGTH = 100;

    private const MAX_ATTEMPTS = 3;

    /**
     * @param  array{display_name: string, headline?: string|null, bio?: string|null, city?: string|null, country?: string|null, website?: string|null, status?: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $data): ProviderProfile
    {
        // Deux créations simultanées peuvent viser le même slug (ou le même user_id) :
        // l'index unique bloque la seconde, qui recommence avec des données à jour.
        return retry(
            self::MAX_ATTEMPTS,
            fn (): ProviderProfile => DB::transaction(fn (): ProviderProfile => $this->save($user, $data)),
            0,
            fn (\Throwable $e): bool => $e instanceof UniqueConstraintViolationException,
        );
    }

    /**
     * @param  array{display_name: string, headline?: string|null, bio?: string|null, city?: string|null, country?: string|null, website?: string|null, status?: string}  $data
     */
    private function save(User $user, array $data): ProviderProfile
    {
        $profile = ProviderProfile::query()->where('user_id', $user->id)->first();

        $status = isset($data['status'])
            ? ProviderProfileStatus::from($data['status'])
            : ($profile->status ?? ProviderProfileStatus::Draft);

        if ($status === ProviderProfileStatus::Published && ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'status' => 'Vous devez vérifier votre adresse email avant de publier votre profil.',
            ]);
        }

        $attributes = [
            'display_name' => $data['display_name'],
            'headline' => $data['headline'] ?? null,
            'bio' => $data['bio'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'website' => $data['website'] ?? null,
            'status' => $status,
            'published_at' => match (true) {
                $status === ProviderProfileStatus::Draft => null,
                $profile?->isPublished() === true => $profile->published_at,
                default => now(),
            },
        ];

        if ($profile === null) {
            $profile = new ProviderProfile($attributes);
            $profile->forceFill([
                'user_id' => $user->id,
                'slug' => $this->uniqueSlug($data['display_name']),
            ]);
        } else {
            $profile->fill($attributes);
        }

        $profile->save();

        return $profile;
    }

    private function uniqueSlug(string $displayName): string
    {
        $base = trim(Str::substr(Str::slug($displayName), 0, self::SLUG_MAX_LENGTH), '-');

        if ($base === '') {
            $base = 'prestataire';
        }

        $slug = $base;
        $suffix = 2;

        while (ProviderProfile::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
