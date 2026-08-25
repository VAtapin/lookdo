<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('stripe_monthly_amount')->nullable();
            $table->unsignedInteger('stripe_yearly_amount')->nullable();
            $table->string('stripe_currency', 3)->nullable();
            $table->timestamp('stripe_synced_at')->nullable();
            $table->text('stripe_sync_error')->nullable();
        });

        Schema::create('ai_usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_classification_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 60)->index();
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->date('usage_date')->index();
            $table->timestamps();
        });

        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type')->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamp('terms_version_at')->nullable();
            $table->timestamp('privacy_version_at')->nullable();
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('ai_usage_records');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['stripe_monthly_amount', 'stripe_yearly_amount', 'stripe_currency', 'stripe_synced_at', 'stripe_sync_error']);
        });
    }
};
