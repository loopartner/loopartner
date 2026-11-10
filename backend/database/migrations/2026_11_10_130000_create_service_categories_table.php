<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catégories de services, partagées par tous les modules (deux niveaux au maximum).
     *
     * - slug est le seul identifiant exposé par l'API.
     * - name contient les traductions : {"fr": "…", "en": "…"}.
     * - Une catégorie ne se supprime pas : elle se désactive (is_active). Les clés étrangères
     *   qui la référencent (parent, profils, projets) sont en "restrict".
     */
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('service_categories')->restrictOnDelete();
            $table->string('slug', 100)->unique();
            $table->json('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};
