<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-managed AI limits: one "global" row, optional per-workspace and
 * per-user overrides. Null columns inherit from the level above
 * (user → workspace → global → config). In a database-per-tenant app this
 * table belongs to the CENTRAL database (packstub-agents.limits_connection).
 */
return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('packstub-agents.limits_connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection)->create('agent_limits', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10); // global | tenant | user
            $table->string('scope_id')->nullable(); // tenant key or user id
            $table->boolean('enabled')->nullable();
            $table->unsignedInteger('turns_per_minute')->nullable();
            $table->unsignedInteger('turns_per_day')->nullable();
            $table->unsignedBigInteger('tokens_per_month')->nullable();
            $table->unsignedBigInteger('user_tokens_per_day')->nullable();
            $table->unsignedBigInteger('user_tokens_per_month')->nullable();
            $table->unsignedInteger('prompt_max_chars')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('agent_limits');
    }
};
