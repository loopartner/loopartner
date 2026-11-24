<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bannière du profil prestataire (décision 82) et image de couverture d'une offre
     * (décision 83). nullOnDelete : supprimer le média (via MediaLibrary, qui supprime aussi le
     * fichier) vide la colonne qui le référence.
     */
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->foreignId('banner_media_id')->nullable()->after('logo_media_id')->constrained('media')->nullOnDelete();
        });

        Schema::table('marketplace_offers', function (Blueprint $table) {
            $table->foreignId('image_media_id')->nullable()->after('city')->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_media_id');
        });

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banner_media_id');
        });
    }
};
