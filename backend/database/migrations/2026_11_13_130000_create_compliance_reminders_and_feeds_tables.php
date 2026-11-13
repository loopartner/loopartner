<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rappels et abonnement au calendrier (module Compliance).
     *
     * - compliance_digests : un récapitulatif par utilisateur et par jour au plus (sent_on, en
     *   DATE) ; la ligne est réservée avant l'envoi (index unique) : une exécution concurrente
     *   ou relancée n'envoie pas deux emails.
     * - compliance_reminder_deliveries : rappel d'une échéance à un délai donné, une seule fois
     *   (index unique), réservé avant l'envoi comme reminder_sent_at des événements.
     * - compliance_calendar_feeds : URL privée d'abonnement, une par utilisateur. Le jeton n'est
     *   jamais stocké : seulement son sélecteur (partie publique, pour retrouver la ligne) et
     *   l'empreinte HMAC-SHA256 (APP_KEY) de sa partie secrète, comparée en temps constant.
     */
    public function up(): void
    {
        Schema::create('compliance_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('sent_on');
            $table->dateTime('created_at');

            $table->unique(['user_id', 'sent_on']);
            $table->index('sent_on');
        });

        Schema::create('compliance_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deadline_id')->constrained('compliance_deadlines')->cascadeOnDelete();
            $table->unsignedSmallInteger('offset_days');
            $table->dateTime('sent_at');

            $table->unique(['deadline_id', 'offset_days']);
            $table->index('sent_at');
        });

        Schema::create('compliance_calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('selector', 16)->unique();
            $table->char('verifier_hash', 64);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_calendar_feeds');
        Schema::dropIfExists('compliance_reminder_deliveries');
        Schema::dropIfExists('compliance_digests');
    }
};
