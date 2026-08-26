<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'image_path')) {
            Schema::table('plans', fn (Blueprint $table) => $table->string('image_path')->nullable()->after('badge_text'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'image_path')) {
            Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('image_path'));
        }
    }
};
