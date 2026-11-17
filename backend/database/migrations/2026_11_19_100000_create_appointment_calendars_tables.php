<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disponibilités des prestataires (module Appointments, décisions 52 à 54).
     *
     * - appointment_calendars : une ligne par prestataire (user_id unique), avec son fuseau et
     *   ses réglages. Cette ligne est verrouillée (SELECT … FOR UPDATE) pendant chaque
     *   réservation : toutes les réservations d'un même prestataire passent l'une après l'autre.
     * - appointment_types : types de rendez-vous (titre, durée, mode de lieu). Le détail du lieu
     *   (adresse du prestataire ou lien de visio) est chiffré par l'application (TEXT).
     * - appointment_availability_rules : plages hebdomadaires en heure locale (jour ISO 1 à 7,
     *   minutes depuis minuit, fin à 1440 au plus), converties en UTC pour chaque date.
     * - appointment_availability_exceptions : fermeture (journée ou plage) ou ouverture
     *   ponctuelle, à une date locale.
     */
    public function up(): void
    {
        Schema::create('appointment_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 64);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('public_page_enabled')->default(false);
            $table->unsignedSmallInteger('slot_step_minutes');
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->unsignedInteger('min_notice_minutes')->default(0);
            $table->unsignedSmallInteger('horizon_days');
            $table->json('reminder_offsets')->nullable();
            $table->timestamps();
        });

        Schema::create('appointment_types', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('calendar_id')->constrained('appointment_calendars')->cascadeOnDelete();
            $table->string('title', 80);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('location_mode', 16);
            $table->text('location_details')->nullable();
            $table->string('visibility', 16)->default('relation');
            $table->boolean('auto_confirm')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->dateTime('moderation_restricted_at')->nullable();
            $table->char('moderation_decision_uuid', 36)->nullable();
            $table->timestamps();

            $table->index(['calendar_id', 'is_active', 'position']);
        });

        Schema::create('appointment_availability_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('appointment_calendars')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->timestamps();

            $table->index(['calendar_id', 'weekday']);
        });

        Schema::create('appointment_availability_exceptions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('calendar_id')->constrained('appointment_calendars')->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 16);
            $table->unsignedSmallInteger('start_minute')->nullable();
            $table->unsignedSmallInteger('end_minute')->nullable();
            $table->timestamps();

            $table->index(['calendar_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_availability_exceptions');
        Schema::dropIfExists('appointment_availability_rules');
        Schema::dropIfExists('appointment_types');
        Schema::dropIfExists('appointment_calendars');
    }
};
