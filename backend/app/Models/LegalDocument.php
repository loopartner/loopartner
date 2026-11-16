<?php

namespace App\Models;

use App\Support\Legal\LegalDocumentType;
use Database\Factories\LegalDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

/**
 * Version d'un document légal (noyau). Publiée par la commande legal:publish
 * (App\Support\Legal\LegalDocuments::publish), jamais modifiée ni supprimée ensuite : une
 * acceptation renvoie toujours au texte exact qui a été accepté (content_hash).
 *
 * Aucun attribut assignable en masse.
 *
 * @property int $id
 * @property string $uuid
 * @property LegalDocumentType $type
 * @property string $version
 * @property array<string, string> $title
 * @property array<string, string> $body
 * @property string $content_hash
 * @property Carbon $effective_at
 * @property bool $is_demo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LegalDocument extends Model
{
    /** @use HasFactory<LegalDocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LegalDocumentType::class,
            'title' => 'array',
            'body' => 'array',
            'effective_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    /**
     * Versions utilisables : une version de démonstration ne l'est que sur le poste local.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        if (! App::environment('local')) {
            $query->where('is_demo', false);
        }
    }

    public function titleIn(string $locale): string
    {
        return (string) ($this->title[$locale] ?? $this->title['fr'] ?? '');
    }

    public function bodyIn(string $locale): string
    {
        return (string) ($this->body[$locale] ?? $this->body['fr'] ?? '');
    }
}
