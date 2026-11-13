<?php

namespace App\Modules\Compliance\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Entier chiffré en base (APP_KEY) : montants en centimes du chiffre d'affaires. Lu et écrit
 * comme un entier PHP ; jamais de flottant.
 *
 * La valeur écrite est vérifiée à l'exécution (un flottant ou une chaîne sont refusés).
 *
 * @implements CastsAttributes<int, mixed>
 */
final class EncryptedInteger implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        $decrypted = Crypt::decryptString((string) $value);

        if (preg_match('/^-?\d+$/', $decrypted) !== 1) {
            throw new InvalidArgumentException('Montant chiffré illisible.');
        }

        return (int) $decrypted;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new InvalidArgumentException('Un montant doit être un entier (centimes).');
        }

        return Crypt::encryptString((string) $value);
    }
}
