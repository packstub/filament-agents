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
        Schema::create('agent_audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_token_id')->nullable()->constrained('agent_tokens')->nullOnDelete();
            $table->string('tool');
            $table->string('mode');
            $table->string('decision');
            $table->json('arguments')->nullable();
            $table->string('summary')->nullable();
            $table->string('ip')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['agent_token_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_audit_entries');
    }
};
