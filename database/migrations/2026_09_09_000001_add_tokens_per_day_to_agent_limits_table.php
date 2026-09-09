<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A daily token budget per workspace, next to the monthly one: a busy day
 * locks the workspace out until midnight instead of until next month.
 *
 * Installs up to 1.4.0 get the column here; the create migration already has
 * it for newer installs, so it is guarded. Folded away at 2.0.
 */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('packstub-agents.limits_connection');
    }

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasColumn('agent_limits', 'tokens_per_day')) {
            return;
        }

        Schema::connection($this->connection)->table('agent_limits', function (Blueprint $table) {
            $table->unsignedBigInteger('tokens_per_day')->nullable()->after('turns_per_day');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('agent_limits', function (Blueprint $table) {
            $table->dropColumn('tokens_per_day');
        });
    }
};
