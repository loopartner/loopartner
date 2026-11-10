<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messagerie (module Directory) : une conversation à deux participants, ouverte par
     * l'acceptation d'une demande de mise en relation.
     *
     * - match_request_id est unique : une demande n'ouvre qu'une conversation.
     * - Les participants et l'expéditeur passent à null à la suppression d'un compte : l'autre
     *   participant garde l'historique, sans lien avec l'identité du compte supprimé, jusqu'à
     *   la purge (orphaned_at + security.retention.orphaned_conversations_months).
     * - last_read_message_id : dernier message lu par le participant (compteur de non-lus).
     * - last_notified_at : dernier email "nouveau message" (au plus un toutes les 15 minutes).
     * - Le curseur des nouveaux messages repose sur messages.id, strictement croissant.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('match_request_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('orphaned_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
