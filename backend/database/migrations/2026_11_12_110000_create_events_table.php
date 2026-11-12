<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Événements de l'agenda (module Events).
     *
     * - uuid identifie l'événement pour son organisateur et pour les admins ; le public ne voit
     *   que le slug, calculé à la première validation puis stable.
     * - user_id (organisateur) passe à null quand un événement à venir est annulé à la
     *   suppression du compte : il reste lisible par ses inscrits, puis il est purgé.
     * - version augmente à chaque changement de contenu : un admin valide la version qu'il a vue.
     * - online_url (lien de connexion) est chiffré par le modèle (cast encrypted) : d'où text.
     * - Dates en UTC ; timezone (IANA) sert à l'affichage et au calcul de l'heure locale.
     * - registrations_count : inscrits actifs, tenu à jour sous verrou de la ligne.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('slug', 191)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);

            $table->string('type', 20);
            $table->string('format', 20);
            $table->string('title', 150);
            $table->text('description');
            $table->string('organizer_name', 120)->nullable();
            $table->string('language', 5);

            $table->string('venue_name', 150)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('address_visibility', 20)->default('public');
            $table->text('online_url')->nullable();
            $table->string('external_url', 2048)->nullable();

            $table->boolean('is_free')->default(true);
            $table->string('ticket_url', 2048)->nullable();

            // dateTime (et non timestamp) pour les dates obligatoires : sur MariaDB/MySQL sans
            // explicit_defaults_for_timestamp, la première colonne TIMESTAMP NOT NULL reçoit
            // ON UPDATE CURRENT_TIMESTAMP et changerait à chaque modification. Valeurs en UTC.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);

            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('registrations_count')->default(0);

            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index(['status', 'ends_at']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'submitted_at']);
            $table->index('country');
            $table->index('city');
            $table->index('language');
        });

        Schema::create('event_category', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();

            $table->primary(['event_id', 'service_category_id']);
            $table->index('service_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_category');
        Schema::dropIfExists('events');
    }
};
