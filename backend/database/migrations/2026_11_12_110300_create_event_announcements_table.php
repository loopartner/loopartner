<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Annonces d'un organisateur à ses inscrits (module Events), lues sur la page de
     * l'événement : l'email de notification ne contient pas leur texte.
     *
     * - has_links : le texte contient un lien ou un nom de domaine ; l'annonce attend alors la
     *   validation d'un admin (pending_review).
     * - decided_by : admin (sans clé étrangère).
     */
    public function up(): void
    {
        Schema::create('event_announcements', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('has_links')->default(false);
            $table->string('status', 20);
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_announcements');
    }
};
