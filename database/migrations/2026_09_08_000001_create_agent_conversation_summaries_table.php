<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rolling summary of a long chat: what the assistant is told in place of
 * the messages that no longer fit the history window (see
 * AgentConversationStore). Lives next to the conversations — in the tenant
 * database of a database-per-tenant app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversation_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id', 36)->unique();
            $table->string('through_message_id', 36)->nullable(); // the newest message the summary covers; null = it came with the chat
            $table->string('source_conversation_id', 36)->nullable(); // the chat this one continues
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversation_summaries');
    }
};
