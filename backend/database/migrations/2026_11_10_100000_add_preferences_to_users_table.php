<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Préférences de l'utilisateur : langue, fuseau horaire IANA et pays ISO 3166-1 alpha-2.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('fr')->after('password');
            $table->string('timezone', 64)->default('UTC')->after('locale');
            $table->char('country', 2)->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'timezone', 'country']);
        });
    }
};
