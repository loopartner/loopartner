<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offres des prestataires (module Marketplace, décisions 39 et 40) : fiches de services
     * publiques, publiées sans validation préalable mais signalables et masquables.
     *
     * - slug : calculé à la première publication, puis stable (URL publique).
     * - price_cents : prix indicatif saisi TTC par le prestataire (aucun calcul de TVA), null
     *   pour une offre « sur devis ».
     * - moderation_* : restriction réversible (module Moderation), sans toucher au statut.
     * - Dates facultatives : timestamp()->nullable() ou dateTime()->nullable().
     */
    public function up(): void
    {
        Schema::create('marketplace_offers', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('slug', 191)->nullable()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('summary', 300);
            $table->text('description');
            $table->string('pricing_mode', 16);
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->string('delivery_mode', 16);
            $table->char('country', 2);
            $table->string('city', 100)->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->dateTime('moderation_restricted_at')->nullable();
            $table->char('moderation_decision_uuid', 36)->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_offers');
    }
};
