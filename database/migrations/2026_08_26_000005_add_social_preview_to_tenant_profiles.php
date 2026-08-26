<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table): void {
            $table->string('social_image_path')->nullable()->after('logo_path');
            $table->string('social_image_source', 20)->nullable()->after('social_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table): void {
            $table->dropColumn(['social_image_path', 'social_image_source']);
        });
    }
};
