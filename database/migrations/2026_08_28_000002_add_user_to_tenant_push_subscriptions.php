<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_push_subscriptions') && ! Schema::hasColumn('tenant_push_subscriptions', 'user_id')) {
            Schema::table('tenant_push_subscriptions', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
                $table->index(['tenant_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_push_subscriptions') && Schema::hasColumn('tenant_push_subscriptions', 'user_id')) {
            Schema::table('tenant_push_subscriptions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
