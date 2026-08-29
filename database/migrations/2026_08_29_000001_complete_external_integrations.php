<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_social_connections')) {
            Schema::create('tenant_social_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('provider', 30);
                $table->string('status', 30)->default('active');
                $table->string('external_account_id')->nullable();
                $table->string('account_name')->nullable();
                $table->text('credentials')->nullable();
                $table->json('scopes')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('last_validated_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'provider']);
            });
        }

        if (Schema::hasTable('tenant_social_drafts')) {
            Schema::table('tenant_social_drafts', function (Blueprint $table) {
                if (! Schema::hasColumn('tenant_social_drafts', 'social_connection_id')) {
                    $table->foreignId('social_connection_id')->nullable()->after('tenant_id')->constrained('tenant_social_connections')->nullOnDelete();
                }
                if (! Schema::hasColumn('tenant_social_drafts', 'external_post_id')) {
                    $table->string('external_post_id')->nullable()->after('published_at');
                }
                if (! Schema::hasColumn('tenant_social_drafts', 'external_post_url')) {
                    $table->string('external_post_url', 1000)->nullable()->after('external_post_id');
                }
                if (! Schema::hasColumn('tenant_social_drafts', 'publish_attempted_at')) {
                    $table->dateTime('publish_attempted_at')->nullable()->after('external_post_url');
                }
                if (! Schema::hasColumn('tenant_social_drafts', 'publish_error')) {
                    $table->text('publish_error')->nullable()->after('publish_attempted_at');
                }
            });
        }

        if (Schema::hasTable('tenant_domains')) {
            Schema::table('tenant_domains', function (Blueprint $table) {
                if (! Schema::hasColumn('tenant_domains', 'provisioning_status')) {
                    $table->string('provisioning_status', 30)->default('pending')->after('status');
                }
                if (! Schema::hasColumn('tenant_domains', 'plesk_site_id')) {
                    $table->string('plesk_site_id')->nullable()->after('verification_token');
                }
                if (! Schema::hasColumn('tenant_domains', 'provisioned_at')) {
                    $table->dateTime('provisioned_at')->nullable()->after('verified_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_social_drafts')) {
            Schema::table('tenant_social_drafts', function (Blueprint $table) {
                if (Schema::hasColumn('tenant_social_drafts', 'social_connection_id')) {
                    $table->dropConstrainedForeignId('social_connection_id');
                }
                foreach (['external_post_id', 'external_post_url', 'publish_attempted_at', 'publish_error'] as $column) {
                    if (Schema::hasColumn('tenant_social_drafts', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
        Schema::dropIfExists('tenant_social_connections');
        if (Schema::hasTable('tenant_domains')) {
            Schema::table('tenant_domains', function (Blueprint $table) {
                foreach (['provisioning_status', 'plesk_site_id', 'provisioned_at'] as $column) {
                    if (Schema::hasColumn('tenant_domains', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
