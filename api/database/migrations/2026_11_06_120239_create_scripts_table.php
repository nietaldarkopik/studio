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
        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('format', 40)->default('mystery_investigation');
            $table->longText('narration')->nullable();
            $table->unsignedSmallInteger('word_count')->default(0);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 24)->default('draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scripts');
    }
};
