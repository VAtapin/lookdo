<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_services', 'archived_at')) {
            Schema::table('tenant_services', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable()->after('active')->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_services', 'archived_at')) {
            Schema::table('tenant_services', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        }
    }
};
