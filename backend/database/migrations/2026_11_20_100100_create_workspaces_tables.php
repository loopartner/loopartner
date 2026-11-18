<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module Workspaces (lot 5, décisions 63 à 65) : espaces privés de pilotage d'un projet.
     *
     * - Les textes saisis (titres, descriptions, notes, sections) et les réponses du copilote
     *   sont chiffrés par l'application (APP_KEY) : colonnes text, jamais indexées ni utilisées
     *   dans une requête.
     * - Tout disparaît avec le compte (cascade depuis users) ou avec le workspace.
     * - linked_project_id : projet Directory relié (nullOnDelete, un workspace par projet).
     * - workspace_copilot_entries : décompte des générations réussies (quota gratuit à vie,
     *   quotas du Premium), gardé tant que le compte existe.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('title');
            $table->text('description')->nullable();
            $table->char('country', 2)->nullable();
            $table->unsignedInteger('budget_min')->nullable();
            $table->unsignedInteger('budget_max')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->boolean('remote_ok')->nullable();
            $table->date('desired_start_date')->nullable();
            $table->string('status', 16)->default('active');
            $table->string('template_code', 64)->nullable();
            $table->foreignId('linked_project_id')->nullable()->unique()->constrained('projects')->nullOnDelete();
            $table->dateTime('activated_at');
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('workspace_goals', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->text('title');
            $table->text('description')->nullable();
            $table->date('target_date')->nullable();
            $table->dateTime('achieved_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('workspace_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->text('title');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('service_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 16)->default('user');
            $table->dateTime('ai_generated_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('workspace_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('workspace_steps')->cascadeOnDelete();
            $table->text('title');
            $table->text('notes')->nullable();
            $table->date('due_date')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('source', 16)->default('user');
            $table->dateTime('ai_generated_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'due_date']);
        });

        Schema::create('workspace_specifications', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('step_id')->nullable()->constrained('workspace_steps')->nullOnDelete();
            $table->foreignId('service_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('title');
            $table->longText('sections');
            $table->string('source', 16)->default('user');
            $table->dateTime('ai_generated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_copilot_suggestions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('step_id')->nullable()->constrained('workspace_steps')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('status', 16)->default('pending');
            $table->longText('content');
            $table->string('prompt_version', 32);
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('workspace_copilot_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->dateTime('generated_at');

            $table->index(['user_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_copilot_entries');
        Schema::dropIfExists('workspace_copilot_suggestions');
        Schema::dropIfExists('workspace_specifications');
        Schema::dropIfExists('workspace_tasks');
        Schema::dropIfExists('workspace_steps');
        Schema::dropIfExists('workspace_goals');
        Schema::dropIfExists('workspaces');
    }
};
