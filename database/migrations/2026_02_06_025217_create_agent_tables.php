<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Agent type definitions (seeded)
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->string('type'); // background, event_triggered, goal_oriented
            $table->boolean('default_enabled')->default(true);
            $table->json('default_config')->nullable();
            $table->timestamps();
        });

        // Per-store agent configuration
        Schema::create('store_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->json('config')->nullable();
            $table->string('permission_level')->default('approve'); // auto, approve, block
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'agent_id']);
        });

        // Agent execution history
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_agent_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, running, completed, failed, cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('trigger_type'); // scheduled, event, manual, goal
            $table->json('trigger_data')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('ai_usage_log_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'agent_id']);
            $table->index(['status']);
            $table->index(['created_at']);
        });

        // Individual actions within a run
        Schema::create('agent_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('action_type'); // price_update, send_notification, markdown_schedule, etc.
            $table->nullableMorphs('actionable'); // polymorphic relation to target entity
            $table->string('status')->default('pending'); // pending, approved, executed, rejected, failed
            $table->boolean('requires_approval')->default(true);
            $table->json('payload'); // before/after values, reasoning
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['agent_run_id']);
        });

        // Goal-oriented agent targets
        Schema::create('agent_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('goal_type'); // sell_item, optimize_category, etc.
            $table->nullableMorphs('target'); // polymorphic relation to target entity
            $table->json('parameters')->nullable();
            $table->string('status')->default('active'); // active, completed, cancelled, failed
            $table->json('progress')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'agent_id']);
            $table->index(['status']);
        });

        // Outcome tracking for learning
        Schema::create('agent_learnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('learning_type');
            $table->json('context');
            $table->json('outcome');
            $table->decimal('success_score', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'agent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_learnings');
        Schema::dropIfExists('agent_goals');
        Schema::dropIfExists('agent_actions');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('store_agents');
        Schema::dropIfExists('agents');
    }
};
