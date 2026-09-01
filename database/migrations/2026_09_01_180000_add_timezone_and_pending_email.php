<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->string('timezone', 80)->default('Europe/Berlin')->after('locale'));
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
            $table->string('pending_email_token', 64)->nullable()->after('pending_email');
        });
        DB::table('tenants')->where('country', 'RU')->update(['timezone' => 'Europe/Moscow']);
        DB::table('tenants')->where('country', 'UA')->update(['timezone' => 'Europe/Kyiv']);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['pending_email', 'pending_email_token']));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('timezone'));
    }
};
