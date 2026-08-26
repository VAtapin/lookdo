<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_acceptances', function (Blueprint $table) {
            $table->timestamp('business_customer_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('legal_acceptances', function (Blueprint $table) {
            $table->dropColumn('business_customer_confirmed_at');
        });
    }
};
