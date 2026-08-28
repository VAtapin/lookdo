<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumn('tenant_services', 'buffer_before_minutes', fn (Blueprint $table) => $table->unsignedSmallInteger('buffer_before_minutes')->default(0)->after('duration_minutes'));
        $this->addColumn('tenant_services', 'buffer_after_minutes', fn (Blueprint $table) => $table->unsignedSmallInteger('buffer_after_minutes')->default(0)->after('buffer_before_minutes'));
        $this->addColumn('tenant_services', 'repeat_interval_days', fn (Blueprint $table) => $table->unsignedSmallInteger('repeat_interval_days')->nullable()->after('currency'));
        $this->addColumn('tenant_services', 'media_allowed', fn (Blueprint $table) => $table->boolean('media_allowed')->default(true)->after('booking_enabled'));

        $this->addColumn('tenant_customers', 'possible_duplicate_of_id', fn (Blueprint $table) => $table->foreignId('possible_duplicate_of_id')->nullable()->after('tenant_id')->constrained('tenant_customers')->nullOnDelete());
        $this->addColumn('tenant_customers', 'notes', fn (Blueprint $table) => $table->text('notes')->nullable()->after('preferred_channel'));
        $this->addColumn('tenant_customers', 'tags', fn (Blueprint $table) => $table->json('tags')->nullable()->after('notes'));
        $this->addColumn('tenant_customers', 'phone_verified_at', fn (Blueprint $table) => $table->timestamp('phone_verified_at')->nullable()->after('tags'));
        $this->addColumn('tenant_customers', 'email_verified_at', fn (Blueprint $table) => $table->timestamp('email_verified_at')->nullable()->after('phone_verified_at'));
        $this->addColumn('tenant_customers', 'service_consent_at', fn (Blueprint $table) => $table->timestamp('service_consent_at')->nullable()->after('email_verified_at'));
        $this->addColumn('tenant_customers', 'marketing_consent_at', fn (Blueprint $table) => $table->timestamp('marketing_consent_at')->nullable()->after('service_consent_at'));
        $this->addColumn('tenant_customers', 'publication_consent_at', fn (Blueprint $table) => $table->timestamp('publication_consent_at')->nullable()->after('marketing_consent_at'));

        $this->addColumn('tenant_requests', 'internal_note', fn (Blueprint $table) => $table->text('internal_note')->nullable()->after('summary'));
        $this->addColumn('tenant_requests', 'completed_at', fn (Blueprint $table) => $table->timestamp('completed_at')->nullable()->after('locale'));
        $this->addColumn('tenant_appointments', 'staff_user_id', fn (Blueprint $table) => $table->foreignId('staff_user_id')->nullable()->after('service_id')->constrained('users')->nullOnDelete());
        $this->addColumn('tenant_appointments', 'reminder_at', fn (Blueprint $table) => $table->timestamp('reminder_at')->nullable()->after('ends_at'));

        if (! Schema::hasTable('tenant_working_hours')) {
            Schema::create('tenant_working_hours', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('weekday');
                $table->boolean('enabled')->default(true);
                $table->time('starts_at')->nullable();
                $table->time('ends_at')->nullable();
                $table->json('breaks')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'weekday']);
            });
        }

        if (! Schema::hasTable('tenant_calendar_blocks')) {
            Schema::create('tenant_calendar_blocks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 30)->default('blocked');
                $table->string('reason')->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->boolean('all_day')->default(false);
                $table->timestamps();
                $table->index(['tenant_id', 'starts_at', 'ends_at']);
            });
        }

        if (! Schema::hasTable('tenant_reminders')) {
            Schema::create('tenant_reminders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
                $table->foreignId('appointment_id')->nullable()->constrained('tenant_appointments')->cascadeOnDelete();
                $table->string('type', 40)->default('appointment');
                $table->string('channel', 30)->default('push');
                $table->string('status', 30)->default('scheduled');
                $table->dateTime('scheduled_at');
                $table->timestamp('sent_at')->nullable();
                $table->text('message');
                $table->text('error')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('tenant_reviews')) {
            Schema::create('tenant_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('tenant_customers')->nullOnDelete();
                $table->foreignId('request_id')->nullable()->constrained('tenant_requests')->nullOnDelete();
                $table->foreignId('portfolio_item_id')->nullable()->constrained('tenant_portfolio_items')->nullOnDelete();
                $table->unsignedTinyInteger('rating');
                $table->string('author_name')->nullable();
                $table->text('body')->nullable();
                $table->boolean('published')->default(false);
                $table->timestamp('received_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'published', 'rating']);
            });
        }

        if (! Schema::hasTable('tenant_social_drafts')) {
            Schema::create('tenant_social_drafts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('portfolio_item_id')->nullable()->constrained('tenant_portfolio_items')->nullOnDelete();
                $table->string('format', 30)->default('feed');
                $table->string('channel', 30)->default('share');
                $table->string('locale', 5)->default('de');
                $table->string('status', 30)->default('draft');
                $table->text('caption')->nullable();
                $table->string('image_path')->nullable();
                $table->string('booking_url')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_social_drafts');
        Schema::dropIfExists('tenant_reviews');
        Schema::dropIfExists('tenant_reminders');
        Schema::dropIfExists('tenant_calendar_blocks');
        Schema::dropIfExists('tenant_working_hours');

        $this->dropColumn('tenant_appointments', 'staff_user_id', true);
        $this->dropColumn('tenant_appointments', 'reminder_at');
        $this->dropColumn('tenant_requests', 'internal_note');
        $this->dropColumn('tenant_requests', 'completed_at');
        $this->dropColumn('tenant_customers', 'possible_duplicate_of_id', true);
        foreach (['notes', 'tags', 'phone_verified_at', 'email_verified_at', 'service_consent_at', 'marketing_consent_at', 'publication_consent_at'] as $column) {
            $this->dropColumn('tenant_customers', $column);
        }
        foreach (['buffer_before_minutes', 'buffer_after_minutes', 'repeat_interval_days', 'media_allowed'] as $column) {
            $this->dropColumn('tenant_services', $column);
        }
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

        Schema::table($table, function (Blueprint $blueprint) use ($column, $constrained) {
            $constrained ? $blueprint->dropConstrainedForeignId($column) : $blueprint->dropColumn($column);
        });
    }
};
