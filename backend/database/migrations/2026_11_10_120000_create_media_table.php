<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Médias (images) envoyés par les utilisateurs, partagés par tous les modules.
     *
     * - uuid est le seul identifiant exposé par l'API.
     * - user_id est en "restrict" : une suppression en cascade effacerait les lignes sans
     *   effacer les fichiers. La base refuse donc de supprimer un utilisateur qui possède
     *   encore des médias : ils doivent être supprimés d'abord par MediaLibrary::deleteOwnedBy().
     * - attached_at reste null tant que le média n'est rattaché à rien (avatar, logo…).
     *
     * Les chaînes indexées restent courtes pour rester compatibles avec MariaDB 10.4 en utf8mb4.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 30)->index();
            $table->string('disk', 30);
            $table->string('path', 255);
            $table->string('mime_type', 50);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();

            $table->index(['attached_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
