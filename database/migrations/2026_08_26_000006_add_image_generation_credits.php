<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('tenant_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('image_generation_free_used')->default(0)->after('social_image_source');
            $table->unsignedInteger('image_generation_credits')->default(0)->after('image_generation_free_used');
        });

        DB::table('tenant_profiles')->where('social_image_source', 'ai')->update(['image_generation_free_used' => 1]);

        Schema::create('image_credit_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_amount', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 20)->default('pending')->index();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_credit_purchases');
        Schema::table('tenant_profiles', fn (Blueprint $table) => $table->dropColumn(['image_generation_free_used', 'image_generation_credits']));
        Schema::table('ai_usage_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('tenant_id'));
    }
};