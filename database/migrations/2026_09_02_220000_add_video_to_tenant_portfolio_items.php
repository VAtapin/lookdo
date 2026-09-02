<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenant_portfolio_items', 'video_path')) {
            return;
        }
        Schema::table('tenant_portfolio_items', function (Blueprint $table): void {
            $table->string('video_path')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tenant_portfolio_items', 'video_path')) {
            return;
        }
        Schema::table('tenant_portfolio_items', function (Blueprint $table): void {
            $table->dropColumn('video_path');
        });
    }
};
