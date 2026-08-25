<?php

namespace Tests\Feature;

use App\Models\BusinessClassification;
use App\Models\BusinessPhrase;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\RequestTemplate;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BackupService;
use App\Services\StripeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class SaasFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_public_platform_returns_localized_plans_and_taxonomy(): void
    {
        $this->withHeader('X-Locale', 'ru')->getJson('/api/platform')
            ->assertOk()->assertJsonPath('locale', 'ru')->assertJsonCount(3, 'plans')
            ->assertJsonFragment(['code' => 'automotive']);
    }

    public function test_dictionary_classification_only_returns_existing_variations(): void
    {
        $response = $this->withHeader('X-Locale', 'ru')->postJson('/api/classify', ['description' => 'Я занимаюсь перетяжкой автомобильных рулей', 'locale' => 'ru'])
            ->assertOk()->assertJsonPath('source', 'fuzzy');
        $variationId = $response->json('candidates.0.variation_id');
        $this->assertDatabaseHas('business_variations', ['id' => $variationId, 'code' => 'automotive.steering-wheel-upholstery']);
    }

    public function test_four_language_catalog_contains_booking_first_brow_template(): void
    {
        $this->withHeader('X-Locale', 'uk')->getJson('/api/platform')
            ->assertOk()
            ->assertJsonPath('locale', 'uk')
            ->assertJsonFragment(['code' => 'beauty'])
            ->assertJsonFragment(['code' => 'beauty.brows']);

        $this->postJson('/api/classify', ['description' => 'корекція та фарбування брів', 'locale' => 'uk'])
            ->assertOk()
            ->assertJsonPath('candidates.0.template_code', 'beauty.brows');

        $template = RequestTemplate::where('code', 'beauty.brows')->firstOrFail();
        $this->assertSame('booking', $template->configuration['engine']);
        $this->assertTrue($template->configuration['capabilities']['booking_primary']);
        $this->assertSame(['de', 'en', 'ru', 'uk'], SystemSetting::read('enabled_locales'));
        $this->assertDatabaseHas('plan_entitlements', ['key' => 'booking_enabled', 'value' => '1']);
    }

    public function test_platform_repair_preserves_template_switches(): void
    {
        $template = RequestTemplate::where('code', 'beauty.brows')->firstOrFail();
        $template->update(['enabled' => false]);

        $this->artisan('lookdo:platform-data', ['--repair' => true])->assertSuccessful();

        $this->assertFalse($template->fresh()->enabled);
    }

    public function test_exact_russian_phrases_and_combined_description_find_expected_templates(): void
    {
        $this->postJson('/api/classify', ['description' => 'ремонт', 'locale' => 'ru'])
            ->assertOk()->assertJsonPath('candidates.0.template_code', 'repair-finishing-installation.general');

        $this->postJson('/api/classify', ['description' => 'ремонт потом перетяжку руля', 'locale' => 'ru'])
            ->assertOk()->assertJsonPath('candidates.0.template_code', 'automotive.steering-wheel-upholstery');
    }

    public function test_unknown_activity_uses_universal_template_and_never_blocks_registration(): void
    {
        $response = $this->postJson('/api/classify', ['description' => 'совершенно неизвестная новая деятельность', 'locale' => 'ru'])
            ->assertOk()->assertJsonPath('source', 'fallback')
            ->assertJsonPath('candidates.0.template_code', 'general-services.general');

        $this->assertNotNull($response->json('candidates.0.variation_id'));
    }

    public function test_ai_can_only_choose_from_existing_classification_candidates(): void
    {
        config(['services.openai.key' => 'test-key', 'services.openai.text_model' => 'gpt-5.6-luna']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-5.6-luna', 'output_text' => '{"choice":1,"confidence":0.91}',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 12],
        ])]);

        $response = $this->postJson('/api/classify', ['description' => 'делаю необычные изделия для салона машины', 'locale' => 'ru'])
            ->assertOk()->assertJsonPath('source', 'ai')->assertJsonPath('ai_model', 'gpt-5.6-luna');

        $this->assertDatabaseHas('business_variations', ['id' => $response->json('variation_id')]);
        $this->assertDatabaseHas('ai_usage_records', ['business_classification_id' => $response->json('id'), 'operation' => 'business_classification']);
    }

    public function test_registration_creates_isolated_tenant_platform_domain_and_subscription(): void
    {
        $variation = BusinessVariation::where('code', 'automotive.steering-wheel-upholstery')->firstOrFail();
        $classification = BusinessClassification::create(['original_text' => 'перетяжка рулей', 'normalized_text' => 'перетяжка рулей', 'category_id' => $variation->category_id, 'variation_id' => $variation->id, 'confidence' => 1, 'source' => 'exact']);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $this->postJson('/api/register', [
            'name' => 'Leonid Owner', 'email' => 'leonid@example.test', 'password' => 'SecurePass123', 'password_confirmation' => 'SecurePass123',
            'business_name' => 'Leonid Deluxe', 'country' => 'DE', 'locale' => 'ru', 'business_description' => 'Перетяжка рулей',
            'classification_id' => $classification->id, 'variation_id' => $variation->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'accept_terms' => true, 'accept_privacy' => true,
        ])->assertCreated()->assertJsonPath('tenant.slug', 'leonid-deluxe');
        $tenant = Tenant::where('slug', 'leonid-deluxe')->firstOrFail();
        $this->assertDatabaseHas('tenant_domains', ['tenant_id' => $tenant->id, 'domain' => 'leonid-deluxe.lookdo.app', 'type' => 'platform', 'status' => 'active']);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'incomplete']);
        $this->assertDatabaseHas('tenant_business_profiles', ['tenant_id' => $tenant->id, 'variation_id' => $variation->id]);
        $this->assertDatabaseHas('legal_acceptances', ['tenant_id' => $tenant->id, 'user_id' => $tenant->users()->firstOrFail()->id]);
    }

    public function test_registration_resolves_fallback_without_classification_or_variation_ids(): void
    {
        $plan = Plan::where('code', 'start')->firstOrFail();

        $this->postJson('/api/register', [
            'name' => 'Fallback Owner', 'email' => 'fallback@example.test', 'password' => 'SecurePass123', 'password_confirmation' => 'SecurePass123',
            'business_name' => 'Fallback Service', 'country' => 'DE', 'locale' => 'ru', 'business_description' => 'неизвестная деятельность',
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'accept_terms' => true, 'accept_privacy' => true,
        ])->assertCreated();

        $tenant = Tenant::where('slug', 'fallback-service')->firstOrFail();
        $this->assertDatabaseHas('tenant_business_profiles', [
            'tenant_id' => $tenant->id,
            'variation_id' => BusinessVariation::where('code', 'general-services.general')->value('id'),
        ]);
    }

    public function test_tenant_user_cannot_read_another_tenant(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $one = Tenant::create(['name' => 'One', 'slug' => 'one', 'country' => 'DE', 'locale' => 'de']);
        $two = Tenant::create(['name' => 'Two', 'slug' => 'two', 'country' => 'DE', 'locale' => 'de']);
        $one->users()->attach($owner, ['role' => 'owner']);
        $two->users()->attach($other, ['role' => 'owner']);
        $this->actingAs($owner)->getJson('/api/tenant/'.$two->id)->assertForbidden();
    }

    public function test_only_super_admin_can_open_control_api(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user)->getJson('/api/control/dashboard')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/control/dashboard')->assertOk()->assertJsonStructure(['tenants', 'mrr']);
    }

    public function test_super_admin_is_created_by_console_command_not_seeder_secrets(): void
    {
        $this->artisan('lookdo:make-super-admin', [
            'email' => 'owner@lookdo.test',
            '--name' => 'Platform Owner',
            '--password' => 'SecureAdmin123',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'owner@lookdo.test',
            'name' => 'Platform Owner',
            'is_active' => true,
            'is_super_admin' => true,
        ]);
    }

    public function test_super_admin_can_manage_plans_and_read_ai_decisions(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->postJson('/api/control/plans', [
            'code' => 'custom', 'name' => ['de' => 'Individuell', 'en' => 'Custom', 'ru' => 'Индивидуальный'],
            'description' => ['de' => 'Test', 'en' => 'Test', 'ru' => 'Тест'], 'price_monthly' => 99, 'price_yearly' => 990,
            'currency' => 'EUR', 'trial_days' => 0, 'is_active' => true, 'is_public' => false, 'sort_order' => 90,
            'entitlements' => ['custom_domain' => '1', 'staff_users' => '5'],
        ])->assertCreated()->assertJsonPath('code', 'custom');

        $this->assertDatabaseHas('plan_entitlements', ['key' => 'staff_users', 'value' => '5']);

        $this->postJson('/api/classify', ['description' => 'устанавливаю входные двери', 'locale' => 'ru'])->assertOk();
        $this->actingAs($admin)->getJson('/api/control/classifications')
            ->assertOk()->assertJsonPath('data.0.variation.code', 'repair-finishing-installation.door-installation');
    }

    public function test_super_admin_can_create_a_complete_client_account(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $variation = BusinessVariation::where('code', 'automotive.steering-wheel-upholstery')->firstOrFail();

        $this->actingAs($admin)->postJson('/api/control/tenants', [
            'name' => 'Golden Wheel', 'owner_name' => 'Leonid', 'owner_email' => 'owner@golden-wheel.test',
            'owner_password' => 'SecureOwner123', 'country' => 'DE', 'locale' => 'ru',
            'business_description' => 'Перетяжка рулей', 'variation_id' => $variation->id,
            'plan_id' => $plan->id, 'complimentary' => true,
        ])->assertCreated()->assertJsonPath('current_subscription.status', 'complimentary');

        $tenant = Tenant::where('name', 'Golden Wheel')->firstOrFail();
        $this->assertDatabaseHas('tenant_domains', ['tenant_id' => $tenant->id, 'domain' => 'golden-wheel.lookdo.app']);
        $this->assertDatabaseHas('tenant_business_profiles', ['tenant_id' => $tenant->id, 'variation_id' => $variation->id]);
    }

    public function test_super_admin_can_open_backup_control_without_creating_a_dump(): void
    {
        config(['backup.path' => storage_path('framework/testing/lookdo-backups')]);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->getJson('/api/control/backups')
            ->assertOk()->assertJsonPath('keep', 14)->assertJsonPath('backups', []);
    }

    public function test_storage_backup_is_finalized_even_when_storage_has_no_user_files(): void
    {
        $path = storage_path('framework/testing/storage-backup');
        File::deleteDirectory($path);
        config(['backup.path' => $path]);
        $method = new ReflectionMethod(BackupService::class, 'archiveStorage');

        $archive = $method->invoke(app(BackupService::class), 'lookdo-test');

        $this->assertFileExists($archive);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($archive) === true);
        $this->assertNotFalse($zip->locateName('.lookdo-backup'));
        $zip->close();
    }

    public function test_operations_migration_can_resume_after_partial_execution(): void
    {
        $migration = require database_path('migrations/2026_08_25_000002_add_platform_operations.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumn('plans', 'stripe_monthly_amount'));
        $this->assertTrue(Schema::hasTable('legal_acceptances'));
    }

    public function test_platform_data_command_repairs_required_records(): void
    {
        BusinessPhrase::query()->delete();

        $this->artisan('lookdo:platform-data', ['--repair' => true])->assertSuccessful();

        $this->assertSame(3, Plan::where('is_active', true)->count());
        $this->assertGreaterThan(20, BusinessPhrase::where('enabled', true)->count());
        $this->assertDatabaseHas('request_templates', ['code' => 'general-services.general', 'enabled' => true]);
    }

    public function test_stripe_plan_sync_replaces_a_price_when_amount_changes(): void
    {
        config(['services.stripe.secret' => 'sk_test']);
        Http::fake(function (HttpRequest $request) {
            if (str_ends_with($request->url(), '/v1/prices')) {
                $amount = (int) $request['unit_amount'];
                $interval = $request['recurring']['interval'] ?? null;

                return Http::response(['id' => $interval === 'year' ? 'price_year' : ($amount === 2500 ? 'price_month_new' : 'price_month')]);
            }

            return Http::response(['id' => 'prod_lookdo']);
        });
        $plan = Plan::where('code', 'start')->firstOrFail();
        app(StripeService::class)->syncPlan($plan);
        $this->assertSame('price_month', $plan->refresh()->stripe_monthly_price_id);

        $plan->update(['price_monthly' => 25]);
        app(StripeService::class)->syncPlan($plan);

        $this->assertSame('price_month_new', $plan->refresh()->stripe_monthly_price_id);
        $this->assertSame(2500, $plan->stripe_monthly_amount);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/prices/price_month') && $request['active'] === 'false');
    }
}
