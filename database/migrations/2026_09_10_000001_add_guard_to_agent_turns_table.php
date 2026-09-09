<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The auth guard a turn was asked on, so the worker signs the person in on
 * the same one — a panel's guard, or whatever guard a plain Laravel app's
 * chat endpoint runs under. Null (rows from before 1.7.0) falls back to the
 * panel's guard or the default one.
 *
 * Installs from before 1.7.0 get the column here; the create migration
 * already has it for newer installs, so it is guarded. Folded away at 2.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_turns', 'guard')) {
                $table->string('guard', 64)->nullable()->after('panel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            if (Schema::hasColumn('agent_turns', 'guard')) {
                $table->dropColumn('guard');
            }
        });
    }
};
