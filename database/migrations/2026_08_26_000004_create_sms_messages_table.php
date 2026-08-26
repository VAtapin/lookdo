<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('provider', 32)->default('seven');
            $table->string('event_type', 64)->index();
            $table->text('recipient');
            $table->char('recipient_hash', 64)->index();
            $table->text('message');
            $table->string('idempotency_key', 191)->nullable();
            $table->string('provider_message_id', 120)->nullable()->index();
            $table->string('status', 32)->default('queued')->index();
            $table->string('provider_status', 64)->nullable();
            $table->unsignedSmallInteger('parts')->default(1);
            $table->decimal('cost', 12, 4)->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->text('provider_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
