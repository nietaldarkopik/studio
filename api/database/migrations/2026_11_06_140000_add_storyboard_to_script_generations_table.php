<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('script_generations', function (Blueprint $table) {
            $table->json('scenes')->nullable();
            $table->timestamp('storyboard_imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('script_generations', function (Blueprint $table) {
            $table->dropColumn(['scenes', 'storyboard_imported_at']);
        });
    }
};
