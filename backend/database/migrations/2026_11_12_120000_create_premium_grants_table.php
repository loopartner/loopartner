<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Périodes de statut premium (module Accounts) : un historique plutôt qu'une colonne sur
     * users, pour garder l'origine de chaque période (attribution manuelle aujourd'hui, paiement
     * plus tard) et laisser le paiement créer ou prolonger des périodes sans toucher à users.
     *
     * - Un utilisateur est premium s'il a une période commencée, pas terminée (ends_at null ou
     *   futur) et pas retirée (revoked_at null).
     * - starts_at est obligatoire : dateTime (UTC), jamais timestamp (pas de ON UPDATE implicite
     *   sur MariaDB/MySQL). ends_at : dateTime nullable (null = sans fin).
     * - external_reference : identifiant chez le prestataire de paiement (abonnement Stripe…),
     *   unique avec source.
     * - granted_by, revoked_by : admin, sans clé étrangère (la trace survit à la suppression de
     *   son compte). Le motif est écrit dans le journal d'audit, pas ici.
     */
    public function up(): void
    {
        Schema::create('premium_grants', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('external_reference', 191)->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_reference']);
            $table->index(['user_id', 'revoked_at', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_grants');
    }
};
