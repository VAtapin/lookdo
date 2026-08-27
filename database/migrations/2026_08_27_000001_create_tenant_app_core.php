<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_services')) {
            Schema::create('tenant_services', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->json('name');
                $table->json('description')->nullable();
                $table->unsignedSmallInteger('duration_minutes')->default(60);
                $table->decimal('price', 10, 2)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->boolean('booking_enabled')->default(true);
                $table->boolean('active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['tenant_id', 'active']);
            });
        }

        if (! Schema::hasTable('tenant_portfolio_items')) {
            Schema::create('tenant_portfolio_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('service_id')->nullable()->constrained('tenant_services')->nullOnDelete();
                $table->json('title')->nullable();
                $table->json('description')->nullable();
                $table->string('image_path')->nullable();
                $table->string('before_image_path')->nullable();
                $table->string('after_image_path')->nullable();
                $table->boolean('featured')->default(false);
                $table->boolean('published')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['tenant_id', 'published', 'featured']);
            });
        }

        if (! Schema::hasTable('tenant_customers')) {
            Schema::create('tenant_customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name')->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('phone_normalized', 40)->nullable();
                $table->string('email')->nullable();
                $table->string('locale', 5)->default('de');
                $table->string('preferred_channel', 30)->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'phone_normalized']);
            });
        }

        if (! Schema::hasTable('tenant_client_tokens')) {
            Schema::create('tenant_client_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('tenant_customers')->cascadeOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'customer_id']);
            });
        }

        if (! Schema::hasTable('tenant_requests')) {
            Schema::create('tenant_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
                $table->foreignId('request_template_id')->nullable()->constrained('request_templates')->nullOnDelete();
                $table->string('number', 30);
                $table->string('status', 30)->default('new');
                $table->json('contact_snapshot')->nullable();
                $table->text('summary')->nullable();
                $table->string('locale', 5)->default('de');
                $table->timestamps();
                $table->unique(['tenant_id', 'number']);
                $table->index(['tenant_id', 'status', 'created_at']);
            });
        }

        if (! Schema::hasTable('tenant_request_values')) {
            Schema::create('tenant_request_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('request_id')->constrained('tenant_requests')->cascadeOnDelete();
                $table->string('field_key', 100);
                $table->json('value')->nullable();
                $table->timestamps();
                $table->unique(['request_id', 'field_key']);
            });
        }

        if (! Schema::hasTable('tenant_media')) {
            Schema::create('tenant_media', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('request_id')->nullable()->constrained('tenant_requests')->cascadeOnDelete();
                $table->foreignId('portfolio_item_id')->nullable()->constrained('tenant_portfolio_items')->cascadeOnDelete();
                $table->string('type', 20)->default('image');
                $table->string('role', 50)->default('condition');
                $table->string('slot_key', 100)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('storage_key');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'request_id']);
            });
        }

        if (! Schema::hasTable('tenant_messages')) {
            Schema::create('tenant_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
                $table->foreignId('request_id')->nullable()->constrained('tenant_requests')->cascadeOnDelete();
                $table->string('sender_type', 20);
                $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'customer_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('tenant_appointments')) {
            Schema::create('tenant_appointments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
                $table->foreignId('service_id')->constrained('tenant_services')->restrictOnDelete();
                $table->string('number', 30);
                $table->string('status', 30)->default('pending');
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->json('contact_snapshot')->nullable();
                $table->text('comment')->nullable();
                $table->string('locale', 5)->default('de');
                $table->timestamps();
                $table->unique(['tenant_id', 'number']);
                $table->index(['tenant_id', 'starts_at', 'ends_at']);
            });
        }

        if (! Schema::hasTable('tenant_push_subscriptions')) {
            Schema::create('tenant_push_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('tenant_customers')->cascadeOnDelete();
                $table->text('endpoint');
                $table->string('endpoint_hash', 64);
                $table->text('public_key')->nullable();
                $table->text('auth_token')->nullable();
                $table->string('locale', 5)->default('de');
                $table->timestamps();
                $table->unique(['tenant_id', 'endpoint_hash'], 'tenant_push_endpoint_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['tenant_push_subscriptions', 'tenant_appointments', 'tenant_messages', 'tenant_media', 'tenant_request_values', 'tenant_requests', 'tenant_client_tokens', 'tenant_customers', 'tenant_portfolio_items', 'tenant_services'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
