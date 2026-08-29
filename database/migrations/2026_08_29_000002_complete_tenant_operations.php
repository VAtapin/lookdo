<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_resources')) {
            Schema::create('tenant_resources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name', 120);
                $table->string('kind', 30)->default('staff');
                $table->string('color', 20)->default('#ff6b00');
                $table->boolean('active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['tenant_id', 'active', 'sort_order'], 'tenant_resources_active_index');
            });
        }
        $this->addColumn('tenant_appointments', 'resource_id', fn (Blueprint $table) => $table->foreignId('resource_id')->nullable()->after('staff_user_id')->constrained('tenant_resources')->nullOnDelete());
        $this->addColumn('tenant_calendar_blocks', 'resource_id', fn (Blueprint $table) => $table->foreignId('resource_id')->nullable()->after('tenant_id')->constrained('tenant_resources')->cascadeOnDelete());

        if (! Schema::hasTable('tenant_segments')) {
            Schema::create('tenant_segments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('kind', 30)->default('manual');
                $table->string('color', 20)->default('#ff6b00');
                $table->json('rules')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique(['tenant_id', 'name']);
            });
        }
        if (! Schema::hasTable('tenant_customer_segment')) {
            Schema::create('tenant_customer_segment', function (Blueprint $table) {
                $table->foreignId('tenant_customer_id')->constrained('tenant_customers')->cascadeOnDelete();
                $table->foreignId('tenant_segment_id')->constrained('tenant_segments')->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['tenant_customer_id', 'tenant_segment_id'], 'tenant_customer_segment_primary');
            });
        }
        $this->addColumn('tenant_reviews', 'master_reply', fn (Blueprint $table) => $table->text('master_reply')->nullable()->after('body'));
        $this->addColumn('tenant_reviews', 'replied_at', fn (Blueprint $table) => $table->timestamp('replied_at')->nullable()->after('received_at'));
    }

    public function down(): void
    {
        foreach (['tenant_customer_segment', 'tenant_segments'] as $table) {
            Schema::dropIfExists($table);
        }
        $this->dropColumn('tenant_calendar_blocks', 'resource_id', true);
        $this->dropColumn('tenant_appointments', 'resource_id', true);
        Schema::dropIfExists('tenant_resources');
        $this->dropColumn('tenant_reviews', 'replied_at');
        $this->dropColumn('tenant_reviews', 'master_reply');
    }

    private function addColumn(string $table, string $column, Closure $definition): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $blueprint) => $definition($blueprint));
        }
    }

    private function dropColumn(string $table, string $column, bool $constrained = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $constrained ? $blueprint->dropConstrainedForeignId($column) : $blueprint->dropColumn($column));
    }
};
