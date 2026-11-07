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
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 120);
            $table->text('narration')->nullable();
            $table->text('visual_description')->nullable();
            $table->text('camera_direction')->nullable();
            $table->unsignedSmallInteger('duration_seconds')->default(5);
            $table->string('status', 24)->default('draft');
            $table->timestamps();

            $table->unique(['project_id', 'position']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
