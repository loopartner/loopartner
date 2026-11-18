<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parcours d'accueil (noyau, lot 5) : seulement les choix de l'utilisateur (parcours masqué,
     * étapes facultatives passées). L'état des étapes est calculé sur les données réelles.
     * Supprimé avec le compte (cascade).
     */
    public function up(): void
    {
        Schema::create('onboarding_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('skipped_steps')->nullable();
            $table->dateTime('dismissed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_states');
    }
};
