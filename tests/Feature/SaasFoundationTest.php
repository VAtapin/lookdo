<?php

namespace Tests\Feature;

use App\Models\BusinessClassification;
use App\Models\BusinessPhrase;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\RequestTemplate;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BackupService;
use App\Services\StripeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    /** @param array<string, int|string> $overrides */
    private function entitlementPayload(array $overrides = []): array
    {
        $defaults = collect(config('plan_entitlements.definitions'))
            ->mapWithKeys(fn (array $definition, string $key) => [$key => (string) ($definition['default'] ?? 0)])
            ->all();

        return array_replace($defaults, $overrides);
    }

    public function test_public_platform_returns_localized_plans_and_taxonomy(): void
    {
        $response = $this->withHeader('X-Locale', 'ru')->getJson('/api/platform')
            ->assertOk()->assertJsonPath('locale', 'ru')->assertJsonCount(3, 'plans')
            ->assertJsonFragment(['code' => 'automotive']);

        $plans = collect($response->json('plans'))->keyBy('code');
        $this->assertSame(1990, $plans['start']['prices']['RUB']['monthly']);
        $this->assertSame(19900, $plans['start']['prices']['RUB']['yearly']);
        $this->assertFalse(collect($plans['start']['features'])->firstWhere('key', 'custom_domain')['included']);
        $this->assertTrue(collect($plans['pro']['features'])->firstWhere('key', 'custom_domain')['included']);
        $this->assertTrue(collect($plans['business']['features'])->firstWhere('key', 'ai')['included']);
        $this->assertStringContainsString('Неограниченные', collect($plans['business']['features'])->firstWhere('key', 'requests')['label']);
    }

    public function test_dictionary_classification_only_returns_existing_variations(): void
    {
        $response = $this->withHeader('X-Locale', 'ru')->postJson('/api/classify', ['description' => 'Я занимаюсь перетяжкой автомобильных рулей', 'locale' => 'ru'])
            ->assertOk()->assertJsonPath('source', 'fuzzy');
        $variationId = $response->json('candidates.0.variation_id');
        $this->assertDatabaseHas('business_variations', ['id' => $variationId, 'code' => 'automotive.steering-wheel-upholstery']);
        $this->assertSame('/brand/leonid-demo.png', $response->json('candidates.0.preview.image'));
    }

    public function test_registration_availability_reports_existing_email_and_slug_before_submit(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);
        Tenant::create(['name' => 'Taken Service', 'slug' => 'taken-service', 'country' => 'DE', 'locale' => 'ru']);

        $this->withHeader('X-Locale', 'ru')->postJson('/api/register/availability', [
            'email' => 'owner@example.test',
            'slug' => 'taken-service',
            'business_name' => 'Taken Service',
        ])->assertOk()
            ->assertJsonPath('email.valid', true)
            ->assertJsonPath('email.available', false)
            ->assertJsonPath('slug.valid', true)
            ->assertJsonPath('slug.available', false)
            ->assertJsonPath('slug.normalized', 'taken-service');

        $suggested = $this->withHeader('X-Locale', 'ru')->postJson('/api/register/availability', [
            'email' => 'new@example.test',
            'business_name' => 'Taken Service',
        ])->assertOk()->json('slug.suggested');

        $this->assertNotSame('taken-service', $suggested);
        $this->assertFalse(Tenant::where('slug', $suggested)->exists());
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
        $plan = Plan::where('code', 'start')->firstOrFail();
        $plan->update(['price_monthly' => 27]);
        $plan->entitlements()->where('key', 'requests_monthly')->update(['value' => '77']);

        $this->artisan('lookdo:platform-data', ['--repair' => true])->assertSuccessful();

        $this->assertFalse($template->fresh()->enabled);
        $this->assertSame('27.00', $plan->fresh()->price_monthly);
        $this->assertSame('77', $plan->entitlements()->where('key', 'requests_monthly')->value('value'));
    }

    public function test_platform_repair_populates_legal_pages_without_overwriting_custom_content(): void
    {
        $contact = PlatformPage::where('key', 'kontakt')->firstOrFail();
        $content = $contact->content;
        $content['de'] = '<h2>Eigener Kontakttext</h2>';
        $contact->update(['content' => $content]);

        $this->artisan('lookdo:platform-data', ['--repair' => true])->assertSuccessful();

        $this->assertSame('<h2>Eigener Kontakttext</h2>', $contact->fresh()->content['de']);
        $this->assertStringContainsString('Privacy policy', PlatformPage::where('key', 'datenschutz')->firstOrFail()->title['en']);
    }

    public function test_public_legal_pages_replace_operator_tokens_from_system_settings(): void
    {
        SystemSetting::updateOrCreate(['key' => 'legal_operator_name'], ['value' => 'LOOKDO Test GmbH']);
        SystemSetting::updateOrCreate(['key' => 'legal_operator_address'], ['value' => "Musterstraße 1\n10115 Berlin"]);

        $this->withHeader('X-Locale', 'de')->getJson('/api/platform/pages/impressum')
            ->assertOk()
            ->assertJsonPath('title', 'Impressum')
            ->assertJsonFragment(['key' => 'impressum']);

        $content = $this->withHeader('X-Locale', 'de')->getJson('/api/platform/pages/impressum')->json('content');
        $this->assertStringContainsString('LOOKDO Test GmbH', $content);
        $this->assertStringContainsString('Musterstraße 1<br />', $content);
        $this->assertStringNotContainsString('{{operator_name}}', $content);
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
        $variation = BusinessVariation::where('code', 'automotive.general')->firstOrFail();
        BusinessPhrase::create([
            'category_id' => $variation->category_id,
            'variation_id' => $variation->id,
            'locale' => 'ru',
            'phrase' => 'уникальный кандидат',
            'normalized_phrase' => 'уникальный кандидат',
            'weight' => 1,
            'enabled' => true,
        ]);
        config(['services.openai.key' => 'test-key', 'services.openai.text_model' => 'gpt-5.6-luna']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-5.6-luna', 'output_text' => '{"choice":1,"confidence":0.91}',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 12],
        ])]);

        $response = $this->postJson('/api/classify', ['description' => 'уникальный заказ', 'locale' => 'ru'])
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
            'currency' => 'RUB',
            'accept_terms' => true, 'accept_privacy' => true,
        ])->assertCreated()
            ->assertJsonPath('tenant.slug', 'leonid-deluxe')
            ->assertJsonPath('payment_required', true);
        $tenant = Tenant::where('slug', 'leonid-deluxe')->firstOrFail();
        $this->assertDatabaseHas('tenant_domains', ['tenant_id' => $tenant->id, 'domain' => 'leonid-deluxe.lookdo.app', 'type' => 'platform', 'status' => 'active']);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'incomplete', 'billing_cycle' => 'monthly', 'currency' => 'RUB', 'unit_amount' => 1990]);
        $this->assertDatabaseHas('tenant_business_profiles', ['tenant_id' => $tenant->id, 'variation_id' => $variation->id]);
        $this->assertDatabaseHas('legal_acceptances', ['tenant_id' => $tenant->id, 'user_id' => $tenant->users()->firstOrFail()->id]);

        $site = $this->getJson('http://leonid-deluxe.lookdo.app/api/tenant-site')
            ->assertOk()
            ->assertJsonPath('name', 'Leonid Deluxe')
            ->assertJsonPath('locale', 'ru')
            ->assertJsonPath('template.preview.image', '/brand/leonid-demo.png');
        $this->assertArrayNotHasKey('contact', $site->json());
        $this->assertArrayNotHasKey('current_subscription', $site->json());
        $this->get('http://leonid-deluxe.lookdo.app/')
            ->assertOk()
            ->assertSee('data-tenant-host="true"', false);

        $this->postJson('/api/tenant/'.$tenant->id.'/checkout', [
            'plan_id' => $plan->id,
            'cycle' => 'monthly',
            'currency' => 'RUB',
        ])->assertUnprocessable();
        $this->assertSame(1, $tenant->subscriptions()->count());
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
            'code' => 'custom', 'name' => ['de' => 'Individuell', 'en' => 'Custom', 'ru' => 'Индивидуальный', 'uk' => 'Індивідуальний'],
            'description' => ['de' => 'Test', 'en' => 'Test', 'ru' => 'Тест', 'uk' => 'Тест'], 'badge_text' => ['de' => '', 'en' => '', 'ru' => '', 'uk' => ''], 'price_monthly' => 99, 'price_yearly' => 990,
            'prices' => ['EUR' => ['monthly' => 99, 'yearly' => 990], 'RUB' => ['monthly' => 9990, 'yearly' => 99900], 'UAH' => ['monthly' => 4490, 'yearly' => 44900]],
            'currency' => 'EUR', 'trial_days' => 0, 'is_active' => true, 'is_public' => false, 'sort_order' => 90,
            'entitlements' => $this->entitlementPayload(['custom_domain' => '1', 'staff_users' => '5']),
        ])->assertCreated()->assertJsonPath('code', 'custom');

        $this->assertDatabaseHas('plan_entitlements', ['key' => 'staff_users', 'value' => '5']);
        $this->assertSame(99900.0, Plan::where('code', 'custom')->firstOrFail()->priceFor('RUB', 'yearly'));

        $this->postJson('/api/classify', ['description' => 'устанавливаю входные двери', 'locale' => 'ru'])->assertOk();
        $this->actingAs($admin)->getJson('/api/control/classifications')
            ->assertOk()->assertJsonPath('data.0.variation.code', 'repair-finishing-installation.door-installation');
    }

    public function test_super_admin_can_translate_a_plan_into_all_platform_languages(): void
    {
        config(['services.openai.key' => 'test-key', 'services.openai.text_model' => 'gpt-5.6-luna']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-5.6-luna',
            'output_text' => json_encode([
                'name' => ['de' => 'Profi', 'en' => 'Professional', 'ru' => 'Профессиональный', 'uk' => 'Професійний'],
                'description' => ['de' => 'Für wachsende Betriebe.', 'en' => 'For growing businesses.', 'ru' => 'Для растущего бизнеса.', 'uk' => 'Для бізнесу, що зростає.'],
                'badge_text' => ['de' => 'Empfohlen', 'en' => 'Recommended', 'ru' => 'Рекомендуем', 'uk' => 'Рекомендуємо'],
            ], JSON_UNESCAPED_UNICODE),
            'usage' => ['input_tokens' => 120, 'output_tokens' => 80],
        ])]);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->postJson('/api/control/plans/translate', [
            'source_locale' => 'ru',
            'name' => 'Профессиональный',
            'description' => 'Для растущего бизнеса.',
            'badge_text' => 'Рекомендуем',
        ])->assertOk()
            ->assertJsonPath('name.de', 'Profi')
            ->assertJsonPath('name.ru', 'Профессиональный')
            ->assertJsonPath('description.uk', 'Для бізнесу, що зростає.');

        $this->assertDatabaseHas('ai_usage_records', ['user_id' => $admin->id, 'operation' => 'plan_translation', 'model' => 'gpt-5.6-luna']);
        Http::assertSent(fn (HttpRequest $request) => $request['text']['format']['type'] === 'json_schema' && $request['text']['format']['strict'] === true && $request['store'] === false);
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

    public function test_super_admin_registries_support_search_sort_pagination_and_content_uploads(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_super_admin' => true]);
        Tenant::create(['name' => 'Zeta Werkstatt', 'slug' => 'zeta-werkstatt', 'country' => 'DE', 'locale' => 'de']);
        Tenant::create(['name' => 'Alpha Werkstatt', 'slug' => 'alpha-werkstatt', 'country' => 'DE', 'locale' => 'de']);

        $this->actingAs($admin)->getJson('/api/control/tenants?search=werkstatt&sort=name&direction=asc&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('data.0.name', 'Alpha Werkstatt');

        $upload = $this->actingAs($admin)->post('/api/control/content-media', [
            'file' => UploadedFile::fake()->image('hero.jpg', 1200, 800),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('name', 'hero.jpg')
            ->assertJsonStructure(['url', 'path', 'mime', 'size']);

        Storage::disk('public')->assertExists($upload->json('path'));
    }

    public function test_control_separates_customer_users_from_administrators_and_loads_tenant_subscription(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $secondAdmin = User::factory()->create(['is_super_admin' => true]);
        $customer = User::factory()->create(['is_super_admin' => false]);
        $tenant = Tenant::create(['name' => 'Client', 'slug' => 'client', 'country' => 'DE', 'locale' => 'de', 'status' => 'active']);
        $tenant->users()->attach($customer, ['role' => 'owner']);
        $tenant->subscriptions()->create(['plan_id' => Plan::where('code', 'start')->value('id'), 'provider' => 'manual', 'status' => 'active', 'started_at' => now()]);

        $this->actingAs($admin)->getJson('/api/control/users')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $customer->id);
        $this->actingAs($admin)->getJson('/api/control/administrators')->assertOk()
            ->assertJsonPath('total', 2);
        $this->actingAs($admin)->putJson('/api/control/users/'.$customer->id, ['is_super_admin' => true])
            ->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/control/users/'.$secondAdmin->id, ['is_active' => false])
            ->assertNotFound();
        $this->actingAs($admin)->getJson('/api/control/tenants/'.$tenant->id)->assertOk()
            ->assertJsonPath('current_subscription.plan.code', 'start');
    }

    public function test_super_admin_can_save_human_settings_and_translate_content(): void
    {
        config(['services.openai.key' => 'test-key', 'services.openai.text_model' => 'gpt-5.6-luna']);
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-5.6-luna',
            'output_text' => json_encode([
                'title' => ['de' => 'Kontakt', 'en' => 'Contact', 'ru' => 'Контакты', 'uk' => 'Контакти'],
                'content' => [
                    'de' => '<p>{{operator_name}}</p>',
                    'en' => '<p>{{operator_name}}</p>',
                    'ru' => '<p>{{operator_name}}</p>',
                    'uk' => '<p>{{operator_name}}</p>',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'usage' => ['input_tokens' => 100, 'output_tokens' => 60],
        ])]);
        $admin = User::factory()->create(['is_super_admin' => true]);
        SystemSetting::updateOrCreate(['key' => 'legal_dispute_statement'], ['value' => 'obsolete']);
        $settings = $this->actingAs($admin)->getJson('/api/control/settings')->assertOk()->json('settings');
        $this->assertArrayNotHasKey('legal_dispute_statement', $settings);
        $settings['legal_operator_name'] = 'LOOKDO GmbH';
        $settings['legal_phone'] = '+49 30 123456';
        $settings['default_request_template_code'] = RequestTemplate::where('enabled', true)->value('code');
        $settings['integrations'] = ['stripe' => true, 'openai' => true];

        $this->actingAs($admin)->putJson('/api/control/settings', ['settings' => $settings])->assertOk()
            ->assertJsonPath('settings.legal_operator_name', 'LOOKDO GmbH');
        $this->assertSame('+49 30 123456', SystemSetting::read('legal_phone'));

        $this->actingAs($admin)->postJson('/api/control/pages/translate', [
            'source_locale' => 'ru',
            'title' => 'Контакты',
            'content' => '<p>{{operator_name}}</p>',
        ])->assertOk()->assertJsonPath('title.de', 'Kontakt')->assertJsonPath('content.en', '<p>{{operator_name}}</p>');
        $this->assertDatabaseHas('ai_usage_records', ['user_id' => $admin->id, 'operation' => 'page_translation']);
    }

    public function test_control_dashboard_returns_clickable_tasks_metrics_and_activity(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        Tenant::create(['name' => 'Recent Client', 'slug' => 'recent-client', 'country' => 'DE', 'locale' => 'de', 'status' => 'active']);

        $this->actingAs($admin)->getJson('/api/control/dashboard')->assertOk()
            ->assertJsonStructure(['tenants', 'mrr', 'metrics' => [['key', 'value', 'to']], 'tasks', 'recent' => [['title', 'to']]])
            ->assertJsonPath('metrics.0.to', '/control/tenants');
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
