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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_id')->nullable();
            $table->string('title', 120);
            $table->text('topic');
            $table->string('status', 24)->default('draft');
            $table->string('mode', 16)->default('manual');
            $table->timestamps();

            $table->unique(['workspace_id', 'client_id']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
