<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inscriptions aux événements gratuits (module Events).
     *
     * - Index unique (event_id, user_id) : une seule inscription par utilisateur et par
     *   événement, même en cas de requêtes simultanées.
     * - status "removed" : inscrit retiré par l'organisateur. La ligne est conservée pour
     *   l'empêcher de se réinscrire. Une désinscription volontaire supprime la ligne.
     */
    public function up(): void
    {
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('registered');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['event_id', 'status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
    }
};
