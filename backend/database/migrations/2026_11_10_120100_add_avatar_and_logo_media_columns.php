<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rattachement des médias : avatar de l'utilisateur et logo du profil prestataire.
     *
     * nullOnDelete : supprimer le média (via MediaLibrary, qui supprime aussi le fichier)
     * vide la colonne qui le référence.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('avatar_media_id')->nullable()->after('country')->constrained('media')->nullOnDelete();
        });

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->foreignId('logo_media_id')->nullable()->after('website')->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logo_media_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avatar_media_id');
        });
    }
};
