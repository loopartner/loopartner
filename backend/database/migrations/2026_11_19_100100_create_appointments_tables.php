<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rendez-vous (module Appointments, décisions 52 à 57).
     *
     * - appointments : un rendez-vous appartient à une demande de mise en relation
     *   (match_request_id, clé étrangère en cascade : il disparaît avec elle, par exemple à la
     *   purge d'une conversation orpheline). provider_id et client_id passent à null à la
     *   suppression d'un compte. Le type est copié (titre, durée, mode) : il peut changer ou
     *   disparaître ensuite. Le lieu (adresse, téléphone, lien de visio) est chiffré (TEXT).
     *   change_* : report proposé en attente de réponse.
     * - appointment_slot_holds : créneaux retenus par les rendez-vous actifs (current) et par
     *   les reports proposés (proposed). slot_key (calendrier et début UTC) est UNIQUE : même si
     *   deux réservations simultanées passaient le contrôle fait sous verrou, la base refuserait
     *   la seconde. Les lignes sont supprimées dès que le créneau est libéré.
     * - appointment_notes : textes libres (motif, messages d'annulation, de refus, de report),
     *   chiffrés, signalables par l'autre partie ; body effacé après la durée de conservation.
     * - appointment_reminders : rappel d'un rendez-vous à un délai, pour une partie, réservé
     *   avant l'envoi (index unique) : jamais deux fois le même rappel.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('match_request_id')->constrained('match_requests')->cascadeOnDelete();
            $table->foreignId('calendar_id')->nullable()->constrained('appointment_calendars')->nullOnDelete();
            $table->foreignId('type_id')->nullable()->constrained('appointment_types')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origin', 16);
            $table->string('created_by', 16);
            $table->string('status', 16);
            $table->string('type_title', 80);
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('location_mode', 16);
            $table->text('location')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('version')->default(1);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('declined_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by', 16)->nullable();
            $table->string('cancellation_reason', 32)->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->dateTime('change_starts_at')->nullable();
            $table->dateTime('change_ends_at')->nullable();
            $table->string('change_by', 16)->nullable();
            $table->dateTime('change_expires_at')->nullable();
            $table->dateTime('location_erased_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'starts_at']);
            $table->index(['client_id', 'starts_at']);
            $table->index(['match_request_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'starts_at']);
            $table->index('change_expires_at');
        });

        Schema::create('appointment_slot_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('calendar_id')->constrained('appointment_calendars')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('slot_key', 64)->unique();

            $table->unique(['appointment_id', 'kind']);
            $table->index(['calendar_id', 'starts_at']);
        });

        Schema::create('appointment_notes', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_side', 16);
            $table->string('kind', 16);
            $table->text('body')->nullable();
            $table->dateTime('erased_at')->nullable();
            $table->dateTime('moderation_restricted_at')->nullable();
            $table->char('moderation_decision_uuid', 36)->nullable();
            $table->timestamps();

            $table->index(['appointment_id', 'created_at']);
        });

        Schema::create('appointment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->string('recipient_side', 16);
            $table->unsignedInteger('offset_minutes');
            $table->dateTime('sent_at');

            $table->unique(['appointment_id', 'recipient_side', 'offset_minutes'], 'appointment_reminders_unique');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reminders');
        Schema::dropIfExists('appointment_notes');
        Schema::dropIfExists('appointment_slot_holds');
        Schema::dropIfExists('appointments');
    }
};
