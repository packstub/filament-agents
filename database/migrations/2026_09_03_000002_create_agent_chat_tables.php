<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations with the assistant (laravel/ai's schema, so its models work
 * unchanged) plus the thumbs up / down on answers. In a database-per-tenant
 * app these belong to the TENANT database: one workspace never sees
 * another's chats, and an export/restore carries them along — publish the
 * migration and move it next to your tenant migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_conversations')) {
            Schema::create('agent_conversations', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('participant_type')->nullable();
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->string('title');
                $table->timestamps();

                $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
            });
        }

        if (! Schema::hasTable('agent_conversation_messages')) {
            Schema::create('agent_conversation_messages', function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('conversation_id', 36)->index();
                $table->string('participant_type')->nullable();
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->string('agent');
                $table->string('role', 25);
                $table->text('content');
                $table->text('attachments');
                $table->text('tool_calls');
                $table->text('tool_results');
                $table->text('usage');
                $table->text('meta');
                $table->text('approval_state')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
                $table->index(['participant_type', 'participant_id'], 'participant_index');
            });
        }

        Schema::create('agent_message_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 36);
            $table->unsignedBigInteger('user_id');
            $table->string('rating', 4); // up | down
            $table->string('comment', 1000)->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_message_feedback');
        Schema::dropIfExists('agent_conversation_messages');
        Schema::dropIfExists('agent_conversations');
    }
};
