<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('provider_invoice_id')->nullable()->unique();
            $table->string('invoice_number', 80)->nullable()->unique();
            $table->string('status', 30)->default('open')->index();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('description');
            $table->decimal('amount_net', 10, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('amount_total', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('recipient_name');
            $table->text('recipient_address')->nullable();
            $table->text('notes')->nullable();
            $table->string('hosted_invoice_url', 2048)->nullable();
            $table->string('invoice_pdf_url', 2048)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->foreignId('subscription_invoice_id')->nullable()->after('subscription_id')->constrained('subscription_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('subscription_invoice_id'));
        Schema::dropIfExists('subscription_invoices');
    }
};
