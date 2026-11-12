<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - article_quota_entries : première soumission de chaque article d'un prestataire ou d'un
     *   entrepreneur (quota gratuit). Une ligne survit à la suppression de l'article : supprimer
     *   un article ne libère pas de place. Supprimée avec le compte.
     * - article_retired_slugs : slugs des articles supprimés après publication. GET
     *   /articles/{slug} répond 410, et aucun nouvel article ne reprend ce slug (les liens qui
     *   pointaient vers l'ancien article ne mènent pas vers un autre contenu).
     *
     * Dates obligatoires en dateTime (UTC).
     */
    public function up(): void
    {
        Schema::create('article_quota_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('article_uuid', 36);
            $table->dateTime('counted_at');

            $table->unique(['user_id', 'article_uuid']);
            $table->index(['user_id', 'counted_at']);
        });

        Schema::create('article_retired_slugs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 191)->unique();
            $table->dateTime('retired_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_retired_slugs');
        Schema::dropIfExists('article_quota_entries');
    }
};
