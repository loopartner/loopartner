<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Abonnement unique à l'agenda (noyau, lot 4) : URL privée, une par utilisateur. Le jeton
     * n'est jamais stocké : seulement son sélecteur (partie publique, pour retrouver la ligne)
     * et l'empreinte HMAC-SHA256 (APP_KEY) de sa partie secrète, comparée en temps constant
     * (App\Support\Calendar\FeedToken). Supprimé avec le compte (clé étrangère en cascade).
     */
    public function up(): void
    {
        Schema::create('agenda_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('selector', 16)->unique();
            $table->char('verifier_hash', 64);
            $table->dateTime('last_accessed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_feeds');
    }
};
