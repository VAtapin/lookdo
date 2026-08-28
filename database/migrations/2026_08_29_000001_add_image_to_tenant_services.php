<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_services') && ! Schema::hasColumn('tenant_services', 'image_path')) {
            Schema::table('tenant_services', function (Blueprint $table) {
                $table->string('image_path')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_services') && Schema::hasColumn('tenant_services', 'image_path')) {
            Schema::table('tenant_services', fn (Blueprint $table) => $table->dropColumn('image_path'));
        }
    }
};
