<?php

namespace App\Modules\Accounts\Actions;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crée le profil prestataire d'un utilisateur s'il n'existe pas, sinon le met à jour.
 *
 * - Le slug est calculé à partir de display_name à la création uniquement, puis ne change
 *   plus : les URL publiques restent stables.
 * - La publication exige un email vérifié et au moins une catégorie de services ; avec
 *   accounts.provider_profile.require_complete_profile (décision 82), aussi une photo (logo), une
 *   bannière et une description d'au moins min_bio_length caractères (422 sur chaque champ).
 * - categories absent : les catégories restent inchangées ; sinon elles sont remplacées.
 * - published_at est fixé au passage en "published" et remis à null au retour en brouillon.
 */
class UpsertProviderProfile
{
    private const SLUG_MAX_LENGTH = 100;

    private const MAX_ATTEMPTS = 3;

    public function __construct(private MediaLibrary $library) {}

    /**
     * @param  array{display_name: string, headline?: string|null, bio?: string|null, city?: string|null, country?: string|null, website?: string|null, status?: string, logo?: string|null, banner?: string|null, categories?: list<string>}  $data
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
     * @param  array{display_name: string, headline?: string|null, bio?: string|null, city?: string|null, country?: string|null, website?: string|null, status?: string, logo?: string|null, banner?: string|null, categories?: list<string>}  $data
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

        // categories absent : les catégories actuelles sont conservées.
        $categoryIds = array_key_exists('categories', $data)
            ? ServiceCategory::query()->whereIn('slug', $data['categories'])->pluck('id')->all()
            : null;

        if ($status === ProviderProfileStatus::Published) {
            $categoryCount = $categoryIds !== null ? count($categoryIds) : ($profile?->categories()->count() ?? 0);

            if ($categoryCount === 0) {
                throw ValidationException::withMessages([
                    'categories' => 'Choisissez au moins une catégorie de services avant de publier votre profil.',
                ]);
            }

            if (config('accounts.provider_profile.require_complete_profile')) {
                $this->assertComplete($profile, $data);
            }
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

        if (array_key_exists('banner', $data)) {
            $profile->forceFill([
                'banner_media_id' => $this->library->replace(
                    $user,
                    MediaPurpose::ProviderBanner,
                    $data['banner'],
                    $profile->banner_media_id,
                    'banner',
                ),
            ]);
        }

        if (array_key_exists('logo', $data)) {
            $profile->forceFill([
                'logo_media_id' => $this->library->replace(
                    $user,
                    MediaPurpose::ProviderLogo,
                    $data['logo'],
                    $profile->logo_media_id,
                    'logo',
                ),
            ]);
        }

        $profile->save();

        if ($categoryIds !== null) {
            $profile->categories()->sync($categoryIds);
        }

        return $profile->load(['logo', 'banner', 'categories']);
    }

    /**
     * Profil complet exigé pour être publié (décision 82). Un média absent de la requête garde
     * sa valeur actuelle ; null le retire. Le média envoyé est vérifié ensuite par
     * MediaLibrary::replace() (422 s'il n'est pas rattachable).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertComplete(?ProviderProfile $profile, array $data): void
    {
        $errors = [];

        $logo = array_key_exists('logo', $data) ? $data['logo'] : $profile?->logo_media_id;
        if ($logo === null) {
            $errors['logo'] = 'Ajoutez une photo de profil avant de publier votre profil.';
        }

        $banner = array_key_exists('banner', $data) ? $data['banner'] : $profile?->banner_media_id;
        if ($banner === null) {
            $errors['banner'] = 'Ajoutez une bannière avant de publier votre profil.';
        }

        $minimum = (int) config('accounts.provider_profile.min_bio_length');
        if (mb_strlen(trim((string) ($data['bio'] ?? ''))) < $minimum) {
            $errors['bio'] = "Décrivez votre activité en {$minimum} caractères au moins avant de publier votre profil.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
