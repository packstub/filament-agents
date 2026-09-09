<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a turn cost and how it went: the provider and model that answered,
 * the token usage, the tools called, the wall time and the finish reason.
 * Written by the job when the turn ends, read on the operator's AI turns
 * page and in the log line. Lives with agent_turns.
 *
 * Installs from 1.4.0 get the columns here; the create migration already has
 * them for newer installs, so every column is guarded. Folded away at 2.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_turns', 'provider')) {
                $table->string('provider', 32)->nullable()->after('model');
            }
            if (! Schema::hasColumn('agent_turns', 'model_name')) {
                $table->string('model_name')->nullable()->after('provider'); // the model that answered (`model` is the picker key)
            }
            if (! Schema::hasColumn('agent_turns', 'usage')) {
                $table->json('usage')->nullable()->after('error'); // laravel/ai's Usage as an array
            }
            if (! Schema::hasColumn('agent_turns', 'tool_calls')) {
                $table->json('tool_calls')->nullable()->after('usage'); // the tool names, in call order
            }
            if (! Schema::hasColumn('agent_turns', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable()->after('tool_calls');
            }
            if (! Schema::hasColumn('agent_turns', 'finish_reason')) {
                $table->string('finish_reason', 24)->nullable()->after('duration_ms'); // stop | length | content_filter | dropped | stopped | refused | failed | …
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropColumn(['provider', 'model_name', 'usage', 'tool_calls', 'duration_ms', 'finish_reason']);
        });
    }
};
