<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'prices')) {
            Schema::table('plans', fn (Blueprint $table) => $table->json('prices')->nullable()->after('currency'));
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'billing_cycle')) {
                $table->string('billing_cycle', 12)->default('monthly')->after('status');
            }
            if (! Schema::hasColumn('subscriptions', 'currency')) {
                $table->string('currency', 3)->default('EUR')->after('billing_cycle');
            }
            if (! Schema::hasColumn('subscriptions', 'unit_amount')) {
                $table->decimal('unit_amount', 12, 2)->nullable()->after('currency');
            }
        });
    }

    public function down(): void
    {
        $subscriptionColumns = array_values(array_filter(
            ['billing_cycle', 'currency', 'unit_amount'],
            fn (string $column) => Schema::hasColumn('subscriptions', $column),
        ));
        if ($subscriptionColumns !== []) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn($subscriptionColumns));
        }
        if (Schema::hasColumn('plans', 'prices')) {
            Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('prices'));
        }
    }
};
