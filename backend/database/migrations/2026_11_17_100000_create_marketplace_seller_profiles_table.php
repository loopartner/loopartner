<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identité professionnelle des prestataires (module Marketplace, décision 37) : mentions
     * obligatoires des devis (arrêté du 24 janvier 2017, Code de la consommation R111-1 et
     * R111-2, Code de commerce R123-237) et qualité déclarée affichée sur les offres (D111-8).
     *
     * - Une ligne par prestataire, supprimée avec le compte.
     * - Colonnes chiffrées par l'application (cast "encrypted", APP_KEY), en TEXT : identité,
     *   numéros, adresse, coordonnées, assurance, médiateur, profession réglementée, mentions
     *   personnalisées. Elles ne sont ni indexées ni utilisées dans une requête.
     * - registration_* : vérification de l'immatriculation par l'API publique de l'État et badge
     *   « Entreprise immatriculée vérifiée » (lot 6). Créées dès maintenant, vides au lot 3 : le
     *   lot 6 n'aura pas à migrer l'identité professionnelle.
     * - last_quote_year, last_quote_sequence : numérotation des devis du prestataire
     *   (D-AAAA-NNNN), avancée sous verrou de cette ligne.
     * - Dates facultatives : timestamp()->nullable().
     */
    public function up(): void
    {
        Schema::create('marketplace_seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('legal_name');
            $table->text('trade_name')->nullable();
            $table->string('legal_form', 32);
            $table->text('registration_number');
            $table->string('registry', 8);
            $table->text('registry_city')->nullable();
            $table->unsignedBigInteger('share_capital_cents')->nullable();
            $table->text('vat_number')->nullable();
            $table->text('address_line1');
            $table->text('address_line2')->nullable();
            $table->text('postal_code');
            $table->text('city');
            $table->char('country', 2);
            $table->text('phone');
            $table->text('email');
            $table->text('insurer_name')->nullable();
            $table->text('insurer_contact')->nullable();
            $table->text('insurance_coverage')->nullable();
            $table->boolean('insurance_ten_year')->default(false);
            $table->text('mediator_name')->nullable();
            $table->text('mediator_url')->nullable();
            $table->text('regulated_profession_title')->nullable();
            $table->text('regulated_profession_state')->nullable();
            $table->text('regulated_profession_body')->nullable();
            $table->text('terms_url')->nullable();
            $table->text('quote_footer')->nullable();
            $table->timestamp('self_certified_at')->nullable();
            $table->string('self_certification_version', 32)->nullable();
            $table->string('registration_verification_status', 20)->nullable();
            $table->string('registration_verification_source', 50)->nullable();
            $table->char('registration_verified_number_hash', 64)->nullable();
            $table->timestamp('registration_verified_at')->nullable();
            $table->timestamp('registration_checked_at')->nullable();
            $table->unsignedSmallInteger('last_quote_year')->nullable();
            $table->unsignedInteger('last_quote_sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_seller_profiles');
    }
};
