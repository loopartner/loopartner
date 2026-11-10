<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projets publiés par les particuliers et les entrepreneurs (module Directory).
     *
     * - uuid est le seul identifiant exposé par l'API.
     * - user_id est en cascade : supprimer un compte supprime ses projets (aucun fichier rattaché).
     * - service_category_id est en "restrict" : une catégorie se désactive, elle ne se supprime pas.
     * - Budget en unités entières de la devise (pas de centimes), devise ISO 4217.
     * - Dates en UTC ; desired_start_date est une date sans heure.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description');
            $table->unsignedInteger('budget_min')->nullable();
            $table->unsignedInteger('budget_max')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->char('country', 2);
            $table->string('city', 100)->nullable();
            $table->boolean('remote_ok')->default(false);
            $table->date('desired_start_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'status']);
            $table->index('published_at');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
