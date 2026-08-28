<?php

namespace Tests\Feature;

use App\Jobs\SendTenantMessagePush;
use App\Models\Plan;
use App\Models\RequestTemplate;
use App\Models\Tenant;
use App\Models\TenantMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_tenant_app_core_migration_resumes_after_partial_mysql_ddl(): void
    {
        Schema::drop('tenant_push_subscriptions');
        Schema::drop('tenant_appointments');

        $migration = require database_path('migrations/2026_08_27_000001_create_tenant_app_core.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('tenant_appointments'));
        $this->assertTrue(Schema::hasTable('tenant_push_subscriptions'));
    }

    public function test_master_message_dispatches_web_push_job_after_commit(): void
    {
        Bus::fake([SendTenantMessagePush::class]);
        $tenant = $this->tenant('push-message', 'automotive.steering-wheel-upholstery');
        $customer = $tenant->customers()->create(['name' => 'Иван', 'phone' => '+4915112345678', 'locale' => 'ru']);

        $message = TenantMessage::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'sender_type' => 'master',
            'body' => 'Мастер ответил на вашу заявку.',
        ]);

        Bus::assertDispatched(SendTenantMessagePush::class, fn ($job) => $job->tenantMessageId === $message->id);
    }

    public function test_vapid_command_checks_configuration_without_external_mutation(): void
    {
        config([
            'services.webpush.vapid_public_key' => 'public-key',
            'services.webpush.vapid_private_key' => 'private-key',
            'services.webpush.subject' => 'mailto:support@lookdo.app',
        ]);

        $this->artisan('lookdo:webpush:keys', ['--check' => true])
            ->expectsOutput('Web Push configuration is complete.')
            ->assertSuccessful();
    }

    public function test_unpaid_tenant_app_is_not_publicly_usable(): void
    {
        $tenant = $this->tenant('unpaid-app', 'automotive.steering-wheel-upholstery', false);

        $this->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertStatus(402)
            ->assertHeader('Content-Language', 'ru')
            ->assertJsonPath('code', 'TENANT_APP_SUBSCRIPTION_INACTIVE')
            ->assertJsonPath('locale', 'ru')
            ->assertJsonPath('message', 'Тестовый период закончился или подписка не активна.');

        $this->withHeader('X-Locale', 'de')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertStatus(402)
            ->assertHeader('Content-Language', 'de')
            ->assertJsonPath('code', 'TENANT_APP_SUBSCRIPTION_INACTIVE')
            ->assertJsonPath('locale', 'de')
            ->assertJsonPath('message', 'Der Testzeitraum ist abgelaufen oder das Abonnement ist nicht aktiv.');
    }

    public function test_active_steering_template_bootstraps_as_localized_full_app(): void
    {
        $tenant = $this->tenant('golden-wheel', 'automotive.steering-wheel-upholstery');

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('tenant.name', 'Golden Wheel')
            ->assertJsonPath('template.engine', 'request')
            ->assertJsonPath('template.layout', 'steering')
            ->assertJsonPath('template.hero.action', 'Оценить мой руль')
            ->assertJsonPath('entitlements.video', false)
            ->assertJsonCount(5, 'template.navigation')
            ->assertJsonCount(3, 'portfolio');

        $this->assertDatabaseCount('tenant_portfolio_items', 3);

        $this->getJson($this->url($tenant, '/manifest.webmanifest'))
            ->assertOk()->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Golden Wheel')->assertJsonPath('scope', '/')->assertJsonPath('display', 'standalone');
    }

    public function test_anonymous_customer_can_send_media_request_and_continue_on_same_device(): void
    {
        Storage::fake('public');
        $tenant = $this->tenant('wheel-request', 'automotive.steering-wheel-upholstery');

        $response = $this->withHeaders(['X-Locale' => 'ru', 'Accept' => 'application/json'])->post($this->url($tenant, '/api/tenant-app/requests'), [
            'name' => 'Иван', 'phone' => '+49 151 12345678', 'summary' => 'Потрескалась кожа сверху',
            'fields' => json_encode(['vehicle_brand' => 'BMW', 'vehicle_model' => 'X5']),
            'media_slots' => json_encode(['overall']),
            'media' => [UploadedFile::fake()->image('wheel.jpg', 1200, 900)],
        ])->assertCreated()->assertJsonPath('request.status', 'new');

        $token = $response->json('token');
        $requestId = $response->json('request.id');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('tenant_requests', ['id' => $requestId, 'tenant_id' => $tenant->id, 'locale' => 'ru']);
        $this->assertDatabaseHas('tenant_request_values', ['request_id' => $requestId, 'field_key' => 'vehicle_brand']);
        $path = $response->json('request.media.0.url');
        $this->assertStringContainsString('/storage/tenant-app/', $path);

        $this->withHeader('X-Lookdo-Client-Token', $token)->getJson($this->url($tenant, '/api/tenant-app/activity'))
            ->assertOk()->assertJsonPath('requests.0.id', $requestId)->assertJsonCount(1, 'requests.0.messages');

        $this->withHeader('X-Lookdo-Client-Token', $token)->postJson($this->url($tenant, "/api/tenant-app/requests/$requestId/messages"), ['body' => 'Можно связаться в WhatsApp'])
            ->assertCreated()->assertJsonPath('message.sender', 'customer');
        $this->assertDatabaseHas('tenant_messages', ['request_id' => $requestId, 'sender_type' => 'customer']);

        $endpoint = 'https://push.example.test/subscription/abc';
        $this->withHeader('X-Lookdo-Client-Token', $token)->postJson($this->url($tenant, '/api/tenant-app/push-subscriptions'), [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-key'],
        ])->assertOk()->assertJsonPath('subscribed', true);
        $this->assertDatabaseHas('tenant_push_subscriptions', ['tenant_id' => $tenant->id, 'endpoint_hash' => hash('sha256', $endpoint)]);
    }

    public function test_brow_template_seeds_services_and_books_only_free_slots(): void
    {
        $tenant = $this->tenant('ivanna-brows', 'beauty.brows');
        $bootstrap = $this->withHeader('X-Locale', 'uk')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()->assertJsonPath('template.engine', 'booking')->assertJsonPath('template.hero.action', 'Записатися');
        $serviceId = $bootstrap->json('services.0.id');
        $this->assertNotNull($serviceId);

        $date = CarbonImmutable::now('Europe/Berlin')->addDay();
        while ($date->dayOfWeekIso > 5) {
            $date = $date->addDay();
        }
        $availability = $this->getJson($this->url($tenant, '/api/tenant-app/availability?service_id='.$serviceId.'&date='.$date->format('Y-m-d')))
            ->assertOk();
        $startsAt = $availability->json('slots.0.starts_at');
        $this->assertNotNull($startsAt);

        $booking = $this->withHeader('X-Locale', 'uk')->postJson($this->url($tenant, '/api/tenant-app/appointments'), [
            'service_id' => $serviceId, 'starts_at' => $startsAt, 'name' => 'Олена', 'phone' => '+380671234567', 'comment' => 'Перша процедура',
        ])->assertCreated()->assertJsonPath('appointment.status', 'pending');
        $this->assertNotEmpty($booking->json('token'));

        $this->postJson($this->url($tenant, '/api/tenant-app/appointments'), [
            'service_id' => $serviceId, 'starts_at' => $startsAt, 'name' => 'Марія', 'phone' => '+380671111111',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_recent_unpaid_subscription_is_activated_as_full_trial(): void
    {
        $plan = Plan::where('code', 'start')->firstOrFail();
        $plan->update(['trial_days' => 14]);
        $tenant = $this->tenant('full-trial', 'automotive.steering-wheel-upholstery', false);

        $migration = require database_path('migrations/2026_08_27_000003_activate_existing_plan_trials.php');
        $migration->up();

        $subscription = $tenant->fresh()->currentSubscription;
        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($subscription->isTrialActive());
        $this->assertGreaterThanOrEqual(13, $subscription->trial_days_remaining);
        $this->assertTrue($tenant->fresh()->hasActiveSubscription());

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk()
            ->assertJsonPath('entitlements.video', true)
            ->assertJsonPath('entitlements.booking', true);
    }

    public function test_manual_access_repairs_the_duplicate_subscription_and_opens_the_public_app(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $tenant = $this->tenant('manual-trial', 'automotive.steering-wheel-upholstery', false);
        $trial = $tenant->currentSubscription;
        $trial->update([
            'provider' => 'lookdo',
            'status' => 'trialing',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(13),
        ]);
        $manual = $tenant->subscriptions()->create([
            'plan_id' => $trial->plan_id,
            'provider' => 'manual',
            'status' => 'complimentary',
            'complimentary' => true,
            'billing_cycle' => 'monthly',
            'currency' => 'EUR',
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(14),
        ]);

        $migration = require database_path('migrations/2026_08_27_000005_separate_manual_access_from_subscriptions.php');
        $migration->up();

        $tenant->refresh();
        $this->assertTrue($tenant->hasManualAccess());
        $this->assertTrue($tenant->hasActiveSubscription());
        $this->assertSame($trial->id, $tenant->currentSubscription->id);
        $this->assertSame('superseded', $manual->fresh()->status);

        $this->withHeader('X-Locale', 'ru')->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertOk();
        $this->actingAs($admin)->getJson('/api/control/subscriptions?search=manual-trial')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $trial->id);

        $firstUntil = $tenant->manual_access_until->copy();
        $this->actingAs($admin)->postJson('/api/control/tenants/'.$tenant->id.'/grant-access', ['days' => 14])
            ->assertOk()
            ->assertJsonPath('tenant.manual_access_active', true);

        $tenant->refresh();
        $this->assertSame(2, $tenant->subscriptions()->count());
        $this->assertSame(1, $tenant->subscriptions()->where('status', '!=', 'superseded')->count());
        $this->assertTrue($tenant->manual_access_until->greaterThanOrEqualTo($firstUntil->addDays(14)));
    }

    public function test_expired_trial_no_longer_grants_application_access(): void
    {
        $tenant = $this->tenant('expired-trial', 'automotive.steering-wheel-upholstery', false);
        $tenant->currentSubscription()->update([
            'status' => 'trialing',
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->subDay(),
        ]);

        $this->assertFalse($tenant->fresh()->hasActiveSubscription());
        $this->getJson($this->url($tenant, '/api/tenant-app/bootstrap'))
            ->assertStatus(402)
            ->assertHeader('Content-Language', 'ru')
            ->assertJsonPath('code', 'TENANT_APP_SUBSCRIPTION_INACTIVE')
            ->assertJsonPath('locale', 'ru')
            ->assertJsonPath('message', 'Тестовый период закончился или подписка не активна.');
    }

    private function tenant(string $slug, string $templateCode, bool $active = true): Tenant
    {
        $template = RequestTemplate::where('code', $templateCode)->firstOrFail();
        $tenant = Tenant::create(['name' => $slug === 'golden-wheel' ? 'Golden Wheel' : ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'status' => 'active', 'country' => 'DE', 'locale' => 'ru', 'business_description' => 'Индивидуальная работа мастера']);
        $tenant->profile()->create(['contact_name' => 'Owner', 'phone' => '+4915112345678', 'city' => 'Berlin', 'primary_color' => '#d6a552', 'secondary_color' => '#111318']);
        $tenant->businessProfile()->create(['category_id' => $template->category_id, 'variation_id' => $template->variation_id, 'request_template_id' => $template->id, 'original_description' => 'Индивидуальная работа мастера']);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => 'manual', 'status' => $active ? 'active' : 'incomplete', 'started_at' => now()]);

        return $tenant;
    }

    private function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->slug.'.'.config('tenancy.platform_domain').$path;
    }
}
