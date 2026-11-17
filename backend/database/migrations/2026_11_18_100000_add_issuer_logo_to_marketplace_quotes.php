<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logo Premium figé à l'envoi d'un devis (décision 51) : copie PNG encodée en base64, au plus
     * marketplace.quotes.logo.max_encoded_bytes (256 Ko). Elle vit et disparaît avec la ligne du
     * devis, n'a ni URL ni fichier, et n'est jamais renvoyée par l'API. Les devis envoyés avant
     * cette migration n'ont pas de copie : leur PDF sort sans logo.
     */
    public function up(): void
    {
        Schema::table('marketplace_quotes', function (Blueprint $table) {
            $table->mediumText('issuer_logo')->nullable()->after('issuer_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_quotes', function (Blueprint $table) {
            $table->dropColumn('issuer_logo');
        });
    }
};
