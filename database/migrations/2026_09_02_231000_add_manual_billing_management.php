<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('manual_status_changed_at')->nullable()->after('discount_percent');
            $table->text('manual_status_reason')->nullable()->after('manual_status_changed_at');
            $table->foreignId('manual_status_changed_by')->nullable()->after('manual_status_reason')->constrained('users')->nullOnDelete();
        });

        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->string('receipt_number', 50)->nullable()->unique()->after('provider_payment_id');
            $table->string('payment_method', 30)->nullable()->index()->after('receipt_number');
            $table->string('reference')->nullable()->after('payment_method');
            $table->text('note')->nullable()->after('reference');
            $table->foreignId('recorded_by_user_id')->nullable()->after('provider_payload')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recorded_by_user_id');
            $table->dropUnique(['receipt_number']);
            $table->dropIndex(['payment_method']);
            $table->dropColumn(['receipt_number', 'payment_method', 'reference', 'note']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('manual_status_changed_by');
            $table->dropColumn(['manual_status_changed_at', 'manual_status_reason']);
        });
    }
};
