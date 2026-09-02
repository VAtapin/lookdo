<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_services', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_services', 'inclusions')) {
                $table->json('inclusions')->nullable()->after('description');
            }
            if (! Schema::hasColumn('tenant_services', 'result')) {
                $table->json('result')->nullable()->after('inclusions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_services', function (Blueprint $table): void {
            if (Schema::hasColumn('tenant_services', 'result')) {
                $table->dropColumn('result');
            }
            if (Schema::hasColumn('tenant_services', 'inclusions')) {
                $table->dropColumn('inclusions');
            }
        });
    }
};
