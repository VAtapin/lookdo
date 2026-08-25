<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_yearly', 10, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('badge_text')->nullable();
            $table->string('stripe_product_id')->nullable()->index();
            $table->string('stripe_monthly_price_id')->nullable();
            $table->string('stripe_yearly_price_id')->nullable();
            $table->timestamps();
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'key']);
        });

        Schema::create('business_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->json('name');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('business_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('business_categories')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->json('name');
            $table->string('template_code')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('request_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('business_categories')->nullOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('business_variations')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('parent_code')->nullable();
            $table->json('name');
            $table->json('configuration');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active')->index();
            $table->string('country', 2)->default('DE');
            $table->string('locale', 5)->default('de');
            $table->foreignId('primary_domain_id')->nullable();
            $table->text('business_description')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('tenant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('city')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 20)->default('#d6a552');
            $table->string('secondary_color', 20)->default('#0a0a0b');
            $table->json('content')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('type')->default('custom');
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('pending')->index();
            $table->string('verification_token')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('ssl_status')->nullable();
            $table->timestamp('ssl_issued_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreign('primary_domain_id')->references('id')->on('tenant_domains')->nullOnDelete();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('provider')->default('manual');
            $table->string('provider_customer_id')->nullable()->index();
            $table->string('provider_subscription_id')->nullable()->index();
            $table->string('status')->default('incomplete')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->boolean('complimentary')->default(false);
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->timestamps();
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('status')->default('paid');
            $table->timestamp('paid_at')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_entitlement_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('business_phrases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('business_categories')->cascadeOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('business_variations')->cascadeOnDelete();
            $table->string('locale', 5)->default('ru');
            $table->string('phrase');
            $table->string('normalized_phrase')->index();
            $table->decimal('weight', 5, 2)->default(1);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['locale', 'normalized_phrase', 'variation_id'], 'business_phrase_unique');
        });

        Schema::create('business_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->text('original_text');
            $table->text('normalized_text');
            $table->foreignId('category_id')->nullable()->constrained('business_categories')->nullOnDelete();
            $table->foreignId('variation_id')->nullable()->constrained('business_variations')->nullOnDelete();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->string('source')->default('fuzzy');
            $table->string('ai_model')->nullable();
            $table->timestamp('confirmed_by_user_at')->nullable();
            $table->json('candidates')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('business_categories');
            $table->foreignId('variation_id')->nullable()->constrained('business_variations');
            $table->foreignId('request_template_id')->nullable()->constrained('request_templates');
            $table->text('original_description')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });

        Schema::create('platform_pages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('title');
            $table->json('content')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->dropForeign(['primary_domain_id']));
        foreach (['audit_logs', 'platform_pages', 'system_settings', 'tenant_business_profiles', 'business_classifications', 'business_phrases', 'tenant_entitlement_overrides', 'subscription_payments', 'subscriptions', 'tenant_domains', 'tenant_profiles', 'tenant_users', 'tenants', 'request_templates', 'business_variations', 'business_categories', 'plan_entitlements', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
