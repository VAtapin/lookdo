<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'stripe_monthly_amount' => fn (Blueprint $table) => $table->unsignedInteger('stripe_monthly_amount')->nullable(),
            'stripe_yearly_amount' => fn (Blueprint $table) => $table->unsignedInteger('stripe_yearly_amount')->nullable(),
            'stripe_currency' => fn (Blueprint $table) => $table->string('stripe_currency', 3)->nullable(),
            'stripe_synced_at' => fn (Blueprint $table) => $table->timestamp('stripe_synced_at')->nullable(),
            'stripe_sync_error' => fn (Blueprint $table) => $table->text('stripe_sync_error')->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn('plans', $column)) {
                Schema::table('plans', $definition);
            }
        }

        if (! Schema::hasTable('ai_usage_records')) {
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
        }

        if (! Schema::hasTable('stripe_webhook_events')) {
            Schema::create('stripe_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id')->unique();
                $table->string('type')->index();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('legal_acceptances')) {
            Schema::create('legal_acceptances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->timestamp('terms_version_at')->nullable();
                $table->timestamp('privacy_version_at')->nullable();
                // MariaDB 10.6 rejects a required TIMESTAMP without an explicit
                // default when nullable TIMESTAMP columns precede it.
                $table->timestamp('accepted_at')->useCurrent();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('ai_usage_records');
        $columns = array_values(array_filter(['stripe_monthly_amount', 'stripe_yearly_amount', 'stripe_currency', 'stripe_synced_at', 'stripe_sync_error'], fn (string $column) => Schema::hasColumn('plans', $column)));
        if ($columns !== []) {
            Schema::table('plans', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
