<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents légaux versionnés (CGU, politique de confidentialité) et acceptations (noyau).
     *
     * - legal_documents : une ligne par version, jamais modifiée ni supprimée après sa
     *   publication (commande legal:publish). effective_at (UTC) : date d'entrée en vigueur ;
     *   content_hash : empreinte SHA-256 du titre et du texte acceptés. is_demo : version de
     *   démonstration (poste local uniquement, refusée par security:check-config).
     * - legal_acceptances : acceptation datée d'une version par un utilisateur, supprimée avec
     *   le compte. Un document accepté ne se supprime pas (clé étrangère en restrict).
     */
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 32);
            $table->string('version', 32);
            $table->json('title');
            $table->json('body');
            $table->char('content_hash', 64);
            $table->dateTime('effective_at');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['type', 'version']);
            $table->index(['type', 'effective_at']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_document_id')->constrained()->restrictOnDelete();
            $table->dateTime('accepted_at');
            $table->string('ip_address', 45)->nullable();

            $table->unique(['user_id', 'legal_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_documents');
    }
};
