<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per turn of the chat: the question (or the approval decisions)
 * waiting its turn, the answer while it streams from the queued job, and how
 * the turn ended. The page reads it while the answer streams and when it is
 * reopened from anywhere. Lives next to the conversations — in the tenant
 * database of a database-per-tenant app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_turns', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36);
            $table->string('participant_type')->nullable();
            $table->unsignedBigInteger('participant_id')->nullable();
            $table->string('message_id', 36)->nullable(); // the recorded question this turn answers; null until it starts, and for decisions
            $table->string('status', 16); // queued | pending | running | done | stopped | failed
            $table->text('input'); // {"prompt": "…"} or {"decisions": {"call-id": true}}
            $table->string('model', 32)->nullable(); // the picker key
            $table->string('provider', 32)->nullable(); // the provider that answered
            $table->string('model_name')->nullable(); // the model that answered (`model` is the picker key)
            $table->string('context')->nullable(); // the page context the chat was opened from
            $table->string('panel')->nullable();
            $table->string('tenant')->nullable(); // the workspace key, when the panel has tenancy
            $table->string('locale', 12)->nullable();
            $table->text('text')->nullable(); // the answer so far
            $table->string('status_text')->nullable(); // Thinking… / the tool being called / Writing…
            $table->text('error')->nullable();
            $table->json('usage')->nullable(); // laravel/ai's Usage as an array
            $table->json('tool_calls')->nullable(); // the tool names, in call order
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('finish_reason', 24)->nullable(); // stop | length | content_filter | dropped | stopped | refused | failed | …
            $table->timestamp('stop_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_turns');
    }
};
