<?php

namespace App\Support\Media;

use App\Models\Media;
use App\Models\User;
use App\Support\Media\Exceptions\TooManyPendingMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * Point d'entrée unique de la brique média pour tous les modules.
 *
 * - store()          : traite et enregistre une image envoyée (média non rattaché) ;
 * - replace()        : rattache un média à un champ (avatar, logo…) et supprime l'ancien ;
 * - deleteOwnedBy()  : supprime tous les médias d'un utilisateur, fichiers compris ;
 * - pruneOrphans()   : supprime les médias jamais rattachés après un délai.
 *
 * Les fichiers sont toujours supprimés après la validation de la transaction en cours :
 * si elle est annulée, les fichiers restent cohérents avec la base.
 */
class MediaLibrary
{
    public function __construct(private ImageProcessor $processor) {}

    /**
     * Enregistre une image déjà validée (type, poids, dimensions) pour l'usage donné.
     *
     * @throws TooManyPendingMedia si l'utilisateur a trop de médias non rattachés
     * @throws ValidationException si l'image ne peut pas être décodée
     */
    public function store(User $owner, UploadedFile $file, MediaPurpose $purpose): Media
    {
        $limits = $purpose->limits() ?? throw new LogicException("L'usage {$purpose->value} n'est pas ouvert à l'upload.");

        $maxPending = (int) config('media.max_pending_per_user');
        if (Media::query()->pending()->where('user_id', $owner->id)->count() >= $maxPending) {
            throw new TooManyPendingMedia($maxPending);
        }

        $image = $this->processor->toWebp((string) $file->getRealPath(), $limits->maxWidth, $limits->maxHeight);

        $uuid = (string) Str::uuid();
        $disk = (string) config('media.disk');
        $path = sprintf('%s/%s/%s.webp', $purpose->value, now()->format('Y/m'), $uuid);

        $stored = Storage::disk($disk)->put($path, $image->contents, [
            'ContentType' => 'image/webp',
            // Le contenu d'un fichier ne change jamais : son nom est unique.
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        if ($stored === false) {
            throw new RuntimeException("Impossible d'écrire le média sur le disque [{$disk}].");
        }

        try {
            $media = new Media;
            $media->forceFill([
                'uuid' => $uuid,
                'user_id' => $owner->id,
                'purpose' => $purpose,
                'disk' => $disk,
                'path' => $path,
                'mime_type' => 'image/webp',
                'size' => strlen($image->contents),
                'width' => $image->width,
                'height' => $image->height,
            ])->save();
        } catch (\Throwable $e) {
            $this->deleteFile($disk, $path);

            throw $e;
        }

        return $media;
    }

    /**
     * Indique si le média peut être rattaché au champ : il appartient à l'utilisateur, a le
     * bon usage, et il est libre ou déjà rattaché à ce même champ ($currentMediaId).
     */
    public function isAttachable(?Media $media, User $owner, MediaPurpose $purpose, ?int $currentMediaId): bool
    {
        return $media !== null
            && $media->user_id === $owner->id
            && $media->purpose === $purpose
            && (! $media->isAttached() || $media->id === $currentMediaId);
    }

    public function findByUuid(string $uuid): ?Media
    {
        return Str::isUuid($uuid) ? Media::query()->where('uuid', $uuid)->first() : null;
    }

    /**
     * Remplace le média d'un champ (avatar, logo…).
     *
     * - $uuid null : retire le média actuel ;
     * - $uuid du média actuel : ne change rien ;
     * - sinon : rattache le nouveau média et supprime l'ancien (ligne et fichier).
     *
     * Le caller enregistre ensuite l'identifiant renvoyé dans sa propre colonne, dans la même
     * transaction. Le média est relu et verrouillé : deux requêtes simultanées ne peuvent pas
     * rattacher le même média.
     *
     * @param  string  $attribute  nom du champ, pour le message d'erreur
     * @return int|null identifiant interne du média rattaché, à stocker dans la colonne du caller
     *
     * @throws ValidationException si le média n'est pas rattachable
     */
    public function replace(User $owner, MediaPurpose $purpose, ?string $uuid, ?int $currentMediaId, string $attribute): ?int
    {
        return DB::transaction(function () use ($owner, $purpose, $uuid, $currentMediaId, $attribute): ?int {
            $new = null;

            if ($uuid !== null) {
                $new = Str::isUuid($uuid)
                    ? Media::query()->where('uuid', $uuid)->lockForUpdate()->first()
                    : null;

                if (! $this->isAttachable($new, $owner, $purpose, $currentMediaId)) {
                    throw ValidationException::withMessages([$attribute => self::notAttachableMessage()]);
                }

                /** @var Media $new */
                if ($new->id === $currentMediaId) {
                    return $currentMediaId;
                }

                $new->forceFill(['attached_at' => now()])->save();
            }

            if ($currentMediaId !== null) {
                $current = Media::query()->whereKey($currentMediaId)->first();

                if ($current !== null) {
                    $this->delete($current);
                }
            }

            return $new?->id;
        });
    }

    public static function notAttachableMessage(): string
    {
        return 'Cette image est introuvable, ne vous appartient pas, ou ne correspond pas à cet usage.';
    }

    /**
     * Supprime un média : la ligne tout de suite, le fichier après la transaction.
     */
    public function delete(Media $media): void
    {
        $disk = $media->disk;
        $path = $media->path;

        $media->delete();

        DB::afterCommit(fn () => $this->deleteFile($disk, $path));
    }

    /**
     * Supprime tous les médias d'un utilisateur, rattachés ou non, avec leurs fichiers.
     * À appeler avant de supprimer l'utilisateur (la clé étrangère l'exige).
     */
    public function deleteOwnedBy(User $owner): void
    {
        Media::query()
            ->where('user_id', $owner->id)
            ->chunkById(100, function (Collection $chunk): void {
                $chunk->each(fn (Media $media) => $this->delete($media));
            });
    }

    /**
     * Supprime les médias jamais rattachés créés il y a plus de $hours heures.
     *
     * La suppression de chaque ligne reste conditionnelle (attached_at toujours null) : un
     * média rattaché pendant le nettoyage est conservé. Le fichier n'est supprimé que si la
     * ligne l'a été.
     *
     * @return int nombre de médias supprimés
     */
    public function pruneOrphans(int $hours): int
    {
        $deleted = 0;

        Media::query()
            ->pending()
            ->where('created_at', '<', now()->subHours($hours))
            ->chunkById(100, function (Collection $chunk) use (&$deleted): void {
                foreach ($chunk as $media) {
                    /** @var Media $media */
                    $rows = Media::query()->whereKey($media->id)->pending()->delete();

                    if ($rows === 1) {
                        $this->deleteFile($media->disk, $media->path);
                        $deleted++;
                    }
                }
            });

        return $deleted;
    }

    /**
     * Une erreur de stockage ne fait pas échouer la requête : elle est journalisée, et le
     * fichier reste sur le disque.
     */
    private function deleteFile(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
