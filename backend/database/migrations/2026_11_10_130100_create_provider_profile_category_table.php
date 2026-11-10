<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catégories de services d'un profil prestataire (1 à 5, validées par l'API).
     *
     * - supprimer un profil supprime ses rattachements (cascade) ;
     * - une catégorie rattachée ne peut pas être supprimée (restrict) : elle se désactive.
     */
    public function up(): void
    {
        Schema::create('provider_profile_category', function (Blueprint $table) {
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();

            $table->primary(['provider_profile_id', 'service_category_id']);
            $table->index('service_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_profile_category');
    }
};
