<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restriction de visibilité décidée par la modération (module Moderation, DSA art. 17),
     * sur chaque contenu signalable. Réversible : le statut du contenu n'est pas modifié ;
     * moderation_restricted_at (UTC) le masque partout où il serait public, et
     * moderation_decision_uuid désigne la décision (seule cette décision le lève).
     *
     * @var list<string>
     */
    private const TABLES = ['provider_profiles', 'projects', 'events', 'event_announcements', 'articles', 'messages', 'match_requests'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dateTime('moderation_restricted_at')->nullable();
                $table->char('moderation_decision_uuid', 36)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['moderation_restricted_at', 'moderation_decision_uuid']);
            });
        }
    }
};
