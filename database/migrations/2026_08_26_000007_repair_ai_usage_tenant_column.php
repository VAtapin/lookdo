<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage_records') && ! Schema::hasColumn('ai_usage_records', 'tenant_id')) {
            Schema::table('ai_usage_records', function (Blueprint $table): void {
                $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // This migration repairs a column owned by the image-generation feature.
        // Rolling it back must not remove a column another migration may have created.
    }
};