<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil public d'un prestataire (au plus un par utilisateur).
     *
     * Les chaînes indexées restent courtes (slug : 120 caractères, soit 480 octets en utf8mb4)
     * pour rester compatibles avec toutes les versions de MySQL et MariaDB.
     */
    public function up(): void
    {
        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name', 100);
            $table->string('slug', 120)->unique();
            $table->string('headline', 160)->nullable();
            $table->text('bio')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_profiles');
    }
};
