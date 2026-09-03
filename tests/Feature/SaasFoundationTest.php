<?php

namespace Tests\Feature;

use App\Jobs\SendSmsMessage;
use App\Models\AiUsageRecord;
use App\Models\BusinessClassification;
use App\Models\BusinessPhrase;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\RequestTemplate;
use App\Models\SmsMessage;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BackupService;
use App\Services\SmsGateway;
use App\Services\SmsService;
use App\Services\StripeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $this->assertSame('/brand/leonid-demo.webp', $response->json('candidates.0.preview.image'));
    }

    public function test_purchase_activity_uses_one_template_with_three_distinct_variations(): void
    {
        $template = RequestTemplate::where('code', 'purchase.general')->firstOrFail();
        $variations = BusinessVariation::whereIn('code', ['purchase.books', 'purchase.vehicles', 'purchase.antiques'])->get();

        $this->assertNull($template->variation_id);
        $this->assertCount(3, $variations);
        $this->assertSame(['purchase.general'], $variations->pluck('template_code')->unique()->values()->all());

        $this->postJson('/api/classify', ['description' => 'скупка книг и журналов', 'locale' => 'ru'])
            ->assertOk()
            ->assertJsonPath('candidates.0.template_code', 'purchase.general')
            ->assertJsonPath('candidates.0.preview.image', '/brand/purchase-books.webp');
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

    public function test_optional_impressum_sections_are_hidden_without_values_and_custom_text_is_preserved(): void
    {
        $page = PlatformPage::where('key', 'impressum')->firstOrFail();
        $content = $page->content;
        $content['de'] = '<h2>Mein eigener Text</h2><p>Bleibt unverändert.</p><h2>Vertretungsberechtigte Person</h2><p>{{representative}}</p><h2>Registereintrag</h2><p>{{register}}</p><h2>Umsatzsteuer-ID</h2><p>{{vat_id}}</p><h2>Haftung für Links</h2><p>Alter Haftungstext.</p>';
        $page->update(['content' => $content]);
        foreach (['legal_representative', 'legal_register', 'legal_vat_id'] as $key) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => '']);
        }

        $hidden = $this->withHeader('X-Locale', 'de')->getJson('/api/platform/pages/impressum')->assertOk()->json('content');
        $this->assertStringContainsString('Mein eigener Text', $hidden);
        $this->assertStringContainsString('Bleibt unverändert.', $hidden);
        $this->assertStringNotContainsString('Vertretungsberechtigte Person', $hidden);
        $this->assertStringNotContainsString('Registereintrag', $hidden);
        $this->assertStringNotContainsString('Umsatzsteuer-ID', $hidden);
        $this->assertStringNotContainsString('Haftung für Links', $hidden);

        SystemSetting::updateOrCreate(['key' => 'legal_representative'], ['value' => 'Max Mustermann']);
        SystemSetting::updateOrCreate(['key' => 'legal_register'], ['value' => 'HRB 12345']);
        SystemSetting::updateOrCreate(['key' => 'legal_vat_id'], ['value' => 'DE123456789']);
        $visible = $this->withHeader('X-Locale', 'de')->getJson('/api/platform/pages/impressum')->assertOk()->json('content');
        $this->assertStringContainsString('Max Mustermann', $visible);
        $this->assertStringContainsString('HRB 12345', $visible);
        $this->assertStringContainsString('DE123456789', $visible);
        $this->assertStringNotContainsString('Haftung für Links', $visible);
    }

    public function test_b2b_platform_does_not_expose_a_consumer_withdrawal_page(): void
    {
        $this->assertDatabaseMissing('platform_pages', ['key' => 'widerruf']);
        $this->getJson('/api/platform/pages/widerruf')->assertNotFound();
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
            'confirm_business_customer' => true, 'accept_terms' => true, 'accept_privacy' => true,
        ])->assertCreated()
            ->assertJsonPath('tenant.slug', 'leonid-deluxe')
            ->assertJsonPath('payment_required', true);
        $tenant = Tenant::where('slug', 'leonid-deluxe')->firstOrFail();
        $this->assertDatabaseHas('tenant_domains', ['tenant_id' => $tenant->id, 'domain' => 'leonid-deluxe.lookdo.app', 'type' => 'platform', 'status' => 'active']);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'incomplete', 'billing_cycle' => 'monthly', 'currency' => 'RUB', 'unit_amount' => 1990]);
        $this->assertDatabaseHas('tenant_business_profiles', ['tenant_id' => $tenant->id, 'variation_id' => $variation->id]);
        $this->assertDatabaseHas('legal_acceptances', ['tenant_id' => $tenant->id, 'user_id' => $tenant->users()->firstOrFail()->id]);
        $this->assertNotNull(DB::table('legal_acceptances')->where('tenant_id', $tenant->id)->value('business_customer_confirmed_at'));

        $site = $this->getJson('http://leonid-deluxe.lookdo.app/api/tenant-site')
            ->assertOk()
            ->assertJsonPath('name', 'Leonid Deluxe')
            ->assertJsonPath('locale', 'ru')
            ->assertJsonPath('template.preview.image', '/brand/leonid-demo.webp');
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
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'confirm_business_customer' => true, 'accept_terms' => true, 'accept_privacy' => true,
        ])->assertCreated();

        $tenant = Tenant::where('slug', 'fallback-service')->firstOrFail();
        $this->assertDatabaseHas('tenant_business_profiles', [
            'tenant_id' => $tenant->id,
            'variation_id' => BusinessVariation::where('code', 'general-services.general')->value('id'),
        ]);
    }

    public function test_registration_with_test_days_starts_full_trial_without_payment(): void
    {
        $variation = BusinessVariation::where('code', 'automotive.steering-wheel-upholstery')->firstOrFail();
        $classification = BusinessClassification::create(['original_text' => 'перетяжка рулей', 'normalized_text' => 'перетяжка рулей', 'category_id' => $variation->category_id, 'variation_id' => $variation->id, 'confidence' => 1, 'source' => 'exact']);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $plan->update(['trial_days' => 14]);

        $this->postJson('/api/register', [
            'name' => 'Trial Owner', 'email' => 'trial@example.test', 'password' => 'SecurePass123', 'password_confirmation' => 'SecurePass123',
            'business_name' => 'Trial Workshop', 'country' => 'DE', 'locale' => 'ru', 'business_description' => 'Перетяжка рулей',
            'classification_id' => $classification->id, 'variation_id' => $variation->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'currency' => 'RUB', 'confirm_business_customer' => true, 'accept_terms' => true, 'accept_privacy' => true,
        ])->assertCreated()
            ->assertJsonPath('payment_required', false)
            ->assertJsonPath('checkout_url', null);

        $tenant = Tenant::where('slug', 'trial-workshop')->firstOrFail();
        $subscription = $tenant->currentSubscription;
        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($subscription->isTrialActive());
        $this->assertSame('lookdo', $subscription->provider);

        $this->getJson('/api/tenant/'.$tenant->id)
            ->assertOk()
            ->assertJsonPath('access.active', true)
            ->assertJsonPath('access.paid', false)
            ->assertJsonPath('access.trial', true)
            ->assertJsonPath('access.trial_days_remaining', 14)
            ->assertJsonPath('entitlements.custom_domain', '1')
            ->assertJsonPath('entitlements.app_languages', '4')
            ->assertJsonPath('entitlements.requests_monthly', '0');
    }

    public function test_registration_requires_business_customer_confirmation(): void
    {
        $plan = Plan::where('code', 'start')->firstOrFail();

        $this->postJson('/api/register', [
            'name' => 'Private Customer', 'email' => 'private@example.test', 'password' => 'SecurePass123', 'password_confirmation' => 'SecurePass123',
            'business_name' => 'Private Test', 'country' => 'DE', 'locale' => 'ru', 'business_description' => 'ремонт',
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'confirm_business_customer' => false, 'accept_terms' => true, 'accept_privacy' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('confirm_business_customer');

        $this->assertDatabaseMissing('users', ['email' => 'private@example.test']);
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

    public function test_plan_image_is_uploaded_exposed_publicly_and_sent_to_stripe(): void
    {
        Storage::fake('public');
        config(['app.url' => 'https://lookdo.test', 'filesystems.disks.public.url' => 'https://lookdo.test/storage', 'services.stripe.secret' => 'sk_live_example']);
        $admin = User::factory()->create(['is_super_admin' => true]);
        $plan = Plan::where('code', 'start')->firstOrFail();

        $upload = $this->actingAs($admin)->post('/api/control/plans/'.$plan->id.'/image', [
            'image' => UploadedFile::fake()->image('start-plan.jpg', 1200, 630),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $upload->json('image_path');
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('/storage/plan-images/', $upload->json('image_url'));
        $platform = $this->withHeader('X-Locale', 'de')->getJson('/api/platform')->assertOk();
        $this->assertSame($upload->json('image_url'), collect($platform->json('plans'))->firstWhere('code', 'start')['image_url']);

        $plan->forceFill(['stripe_product_id' => null, 'stripe_monthly_price_id' => null, 'stripe_yearly_price_id' => null])->save();
        Http::fake(function (HttpRequest $request) {
            if (str_ends_with($request->url(), '/v1/prices')) {
                return Http::response(['id' => ($request['recurring']['interval'] ?? null) === 'year' ? 'price_year' : 'price_month']);
            }

            return Http::response(['id' => 'prod_start']);
        });

        app(StripeService::class)->syncPlan($plan->refresh());

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/products')
            && str_contains(urldecode($request->body()), (string) $upload->json('image_url')));
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
        ])->assertCreated()
            ->assertJsonPath('manual_access_active', true)
            ->assertJsonPath('current_subscription.status', 'incomplete');

        $tenant = Tenant::where('name', 'Golden Wheel')->firstOrFail();
        $this->assertDatabaseHas('tenant_domains', ['tenant_id' => $tenant->id, 'domain' => 'golden-wheel.lookdo.app']);
        $this->assertDatabaseHas('tenant_business_profiles', ['tenant_id' => $tenant->id, 'variation_id' => $variation->id]);
    }

    public function test_super_admin_can_grant_temporary_access_without_marking_it_as_paid(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Manual Access', 'slug' => 'manual-access', 'country' => 'DE', 'locale' => 'ru', 'status' => 'active']);
        $tenant->users()->attach($owner, ['role' => 'owner']);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $tenant->subscriptions()->create([
            'plan_id' => $plan->id,
            'provider' => 'stripe',
            'status' => 'incomplete',
            'billing_cycle' => 'monthly',
            'currency' => 'EUR',
            'unit_amount' => $plan->priceFor('EUR', 'monthly'),
            'started_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/api/control/tenants/'.$tenant->id.'/grant-access', ['days' => 30])
            ->assertOk()
            ->assertJsonPath('subscription.provider', 'stripe')
            ->assertJsonPath('subscription.status', 'incomplete')
            ->assertJsonPath('tenant.manual_access_active', true);

        $tenant = $tenant->fresh();
        $subscription = $tenant->currentSubscription;
        $this->assertFalse($subscription->complimentary);
        $this->assertFalse($subscription->isPaidAccess());
        $this->assertFalse($subscription->isComplimentaryAccess());
        $this->assertTrue($tenant->hasManualAccess());
        $this->assertTrue($tenant->hasActiveSubscription());
        $this->assertGreaterThanOrEqual(29, $tenant->manual_access_days_remaining);
        $this->assertSame(1, $tenant->subscriptions()->count());

        $this->actingAs($admin)->getJson('/api/control/tenants?search=Manual%20Access')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.manual_access_active', true);
    }

    public function test_changing_a_clients_plan_does_not_fake_a_payment(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Unpaid Client', 'slug' => 'unpaid-client', 'country' => 'DE', 'locale' => 'de', 'status' => 'active']);
        $tenant->users()->attach($owner, ['role' => 'owner']);
        $start = Plan::where('code', 'start')->firstOrFail();
        $pro = Plan::where('code', 'pro')->firstOrFail();
        $subscription = $tenant->subscriptions()->create(['plan_id' => $start->id, 'provider' => 'stripe', 'status' => 'incomplete', 'started_at' => now()]);

        $this->actingAs($admin)->putJson('/api/control/tenants/'.$tenant->id, ['plan_id' => $pro->id])
            ->assertOk()
            ->assertJsonPath('current_subscription.plan_id', $pro->id)
            ->assertJsonPath('current_subscription.access_state', 'unpaid');

        $subscription->refresh();
        $this->assertSame('incomplete', $subscription->status);
        $this->assertSame('stripe', $subscription->provider);
        $this->assertFalse($subscription->grantsAccess());
    }

    public function test_reconciliation_restores_recent_existing_plan_trials(): void
    {
        $plan = Plan::where('code', 'start')->firstOrFail();
        $plan->update(['trial_days' => 14]);
        $tenant = Tenant::create(['name' => 'Yesterday Client', 'slug' => 'yesterday-client', 'country' => 'DE', 'locale' => 'ru', 'status' => 'active']);
        $subscription = $tenant->subscriptions()->create([
            'plan_id' => $plan->id,
            'provider' => 'stripe',
            'status' => 'incomplete',
            'started_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $migration = require database_path('migrations/2026_08_27_000004_reconcile_existing_plan_trials.php');
        $migration->up();

        $subscription->refresh();
        $this->assertSame('lookdo', $subscription->provider);
        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($subscription->isTrialActive());
        $this->assertTrue($tenant->fresh()->hasActiveSubscription());
        $this->assertGreaterThanOrEqual(12, $subscription->access_days_remaining);
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

    public function test_control_exposes_only_the_client_owner_and_keeps_administrators_separate(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $secondAdmin = User::factory()->create(['is_super_admin' => true]);
        $owner = User::factory()->create(['is_super_admin' => false]);
        $staff = User::factory()->create(['is_super_admin' => false]);
        $tenant = Tenant::create(['name' => 'Client', 'slug' => 'client', 'country' => 'DE', 'locale' => 'de', 'status' => 'active']);
        $tenant->users()->attach($owner, ['role' => 'owner']);
        $tenant->users()->attach($staff, ['role' => 'staff']);
        $tenant->subscriptions()->create(['plan_id' => Plan::where('code', 'start')->value('id'), 'provider' => 'manual', 'status' => 'active', 'started_at' => now()]);

        $this->actingAs($admin)->getJson('/api/control/users')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/control/administrators')->assertOk()->assertJsonPath('total', 2);
        $this->actingAs($admin)->getJson('/api/control/tenants/'.$tenant->id)->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $owner->id)
            ->assertJsonPath('current_subscription.plan.code', 'start');
        $this->actingAs($admin)->putJson('/api/control/tenants/'.$tenant->id, [
            'name' => 'Client GmbH', 'owner_name' => 'Main Owner', 'owner_email' => 'owner-updated@example.test',
        ])->assertOk();
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Client GmbH']);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'name' => 'Main Owner', 'email' => 'owner-updated@example.test']);
        $this->actingAs($admin)->putJson('/api/control/users/'.$staff->id, ['is_active' => false])->assertNotFound();
        $this->actingAs($admin)->putJson('/api/control/users/'.$secondAdmin->id, ['is_active' => false])->assertNotFound();
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
        $settings['integrations'] = ['stripe' => true, 'openai' => true, 'sms' => false];

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

    public function test_maintenance_mode_blocks_public_platform_but_keeps_super_admin_and_webhooks_available(): void
    {
        SystemSetting::updateOrCreate(['key' => 'maintenance'], ['value' => true]);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->get('/de')->assertStatus(503)->assertSee('Wir sind gleich wieder da.');
        $this->getJson('/api/platform')->assertStatus(503)->assertJsonPath('maintenance', true);
        $this->get('/control/settings/operation')->assertOk();
        $this->actingAs($admin)->getJson('/api/control/settings')->assertOk();
        $this->postJson('/api/stripe/webhook')->assertStatus(400);
    }

    public function test_settings_sections_and_all_four_social_preview_slots_are_available(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $response = $this->actingAs($admin)->getJson('/api/control/settings')->assertOk();

        $response->assertJsonPath('settings.social_share_images.de', '/brand/lookdo-social-de.jpg')
            ->assertJsonPath('settings.social_share_images.en', '/brand/lookdo-social-en.jpg')
            ->assertJsonPath('settings.social_share_images.ru', '/brand/lookdo-social-ru.jpg')
            ->assertJsonPath('settings.social_share_images.uk', '/brand/lookdo-social-uk.jpg');
        $this->actingAs($admin)->get('/control/settings/media')->assertOk();
    }

    public function test_control_dashboard_returns_clickable_tasks_metrics_and_activity(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $tenant = Tenant::create(['name' => 'Recent Client', 'slug' => 'recent-client', 'country' => 'DE', 'locale' => 'de', 'status' => 'active']);
        AiUsageRecord::create([
            'tenant_id' => $tenant->id,
            'operation' => 'translation',
            'model' => 'gpt-test',
            'input_tokens' => 120,
            'output_tokens' => 30,
            'cost' => .0123,
            'usage_date' => today(),
        ]);

        $this->actingAs($admin)->getJson('/api/control/dashboard')->assertOk()
            ->assertJsonStructure(['tenants', 'mrr', 'metrics' => [['key', 'value', 'to']], 'ai_usage' => ['local', 'openai'], 'tasks', 'recent' => [['title', 'to']]])
            ->assertJsonPath('ai_usage.local.requests', 1)
            ->assertJsonPath('ai_usage.local.input_tokens', 120)
            ->assertJsonPath('ai_usage.local.by_tenant.0.tenant_name', 'Recent Client')
            ->assertJsonPath('ai_usage.openai.status', 'not_configured')
            ->assertJsonFragment(['key' => 'ai_spend_month', 'to' => '/control/settings/openai'])
            ->assertJsonPath('metrics.0.to', '/control/tenants');
    }

    public function test_openai_admin_key_is_encrypted_and_usage_costs_can_be_synchronized(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $settings = $this->actingAs($admin)->getJson('/api/control/settings')->assertOk()->json('settings');
        $settings['openai_project_id'] = 'proj_lookdo';
        $settings['openai_admin_key'] = 'sk-admin-lookdo-secret';

        $this->actingAs($admin)->putJson('/api/control/settings', ['settings' => $settings])
            ->assertOk()
            ->assertJsonPath('openai.admin_key_configured', true)
            ->assertJsonPath('openai.project_id', 'proj_lookdo')
            ->assertJsonMissing(['openai_admin_key' => 'sk-admin-lookdo-secret']);

        $this->assertSame('sk-admin-lookdo-secret', SystemSetting::readSecret('openai_admin_key'));
        $this->assertNotSame('sk-admin-lookdo-secret', SystemSetting::where('key', 'openai_admin_key')->firstOrFail()->value);

        Http::fake([
            'api.openai.com/v1/organization/costs*' => Http::response(['data' => [['results' => [[
                'amount' => ['value' => .106, 'currency' => 'usd'],
                'line_item' => 'Model usage',
            ]]]]]),
            'api.openai.com/v1/organization/usage/completions*' => Http::response(['data' => [['results' => [[
                'input_tokens' => 102,
                'input_cached_tokens' => 0,
                'output_tokens' => 45,
                'num_model_requests' => 3,
            ]]]]]),
            'api.openai.com/v1/organization/usage/images*' => Http::response(['data' => [['results' => [[
                'images' => 2,
                'num_model_requests' => 2,
            ]]]]]),
        ]);

        $this->actingAs($admin)->postJson('/api/control/openai/test')->assertOk()
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('project_id', 'proj_lookdo')
            ->assertJsonPath('month_cost', .106)
            ->assertJsonPath('requests', 5)
            ->assertJsonPath('input_tokens', 102)
            ->assertJsonPath('output_tokens', 45)
            ->assertJsonPath('images', 2);

        Http::assertSentCount(3);
        Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer sk-admin-lookdo-secret'));
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

    public function test_stripe_checkout_serializes_automatic_tax_boolean_as_a_string(): void
    {
        config([
            'services.stripe.secret' => 'sk_live_example',
            'services.stripe.automatic_tax' => true,
        ]);
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_live_subscription_1',
                'url' => 'https://checkout.stripe.test/subscription',
            ]),
        ]);
        $owner = User::factory()->create();
        $tenant = Tenant::create([
            'name' => 'Checkout Test',
            'slug' => 'checkout-test',
            'country' => 'DE',
            'locale' => 'de',
            'status' => 'active',
        ]);
        $tenant->users()->attach($owner, ['role' => 'owner']);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $plan->forceFill([
            'stripe_product_id' => 'prod_start',
            'stripe_monthly_price_id' => 'price_start_monthly',
            'stripe_currency' => 'EUR',
        ])->save();
        $tenant->subscriptions()->create([
            'plan_id' => $plan->id,
            'provider' => 'stripe',
            'status' => 'incomplete',
            'billing_cycle' => 'monthly',
            'currency' => 'EUR',
            'unit_amount' => $plan->priceFor('EUR', 'monthly'),
            'started_at' => now(),
        ]);

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/checkout', [
            'plan_id' => $plan->id,
            'cycle' => 'monthly',
            'currency' => 'EUR',
        ])->assertOk()->assertJsonPath('checkout_url', 'https://checkout.stripe.test/subscription');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && data_get($request->data(), 'automatic_tax.enabled') === 'true');
    }

    public function test_checkout_during_trial_keeps_application_active_until_stripe_confirms_payment(): void
    {
        config(['services.stripe.secret' => 'sk_live_example']);
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_live_trial_upgrade',
                'url' => 'https://checkout.stripe.test/trial-upgrade',
            ]),
        ]);
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Trial Upgrade', 'slug' => 'trial-upgrade', 'country' => 'DE', 'locale' => 'ru', 'status' => 'active']);
        $tenant->users()->attach($owner, ['role' => 'owner']);
        $start = Plan::where('code', 'start')->firstOrFail();
        $pro = Plan::where('code', 'pro')->firstOrFail();
        $pro->forceFill(['stripe_product_id' => 'prod_pro', 'stripe_monthly_price_id' => 'price_pro_monthly', 'stripe_currency' => 'EUR'])->save();
        $subscription = $tenant->subscriptions()->create([
            'plan_id' => $start->id,
            'provider' => 'lookdo',
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'currency' => 'EUR',
            'unit_amount' => $start->priceFor('EUR', 'monthly'),
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(14),
        ]);

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/checkout', [
            'plan_id' => $pro->id,
            'cycle' => 'monthly',
            'currency' => 'EUR',
        ])->assertOk()->assertJsonPath('checkout_url', 'https://checkout.stripe.test/trial-upgrade');

        $this->assertSame(1, $tenant->subscriptions()->count());
        $subscription->refresh();
        $this->assertSame($pro->id, $subscription->plan_id);
        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($tenant->fresh()->hasActiveSubscription());
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && data_get($request->data(), 'metadata.subscription_id') === (string) $subscription->id
            && data_get($request->data(), 'line_items.0.price') === 'price_pro_monthly');
    }

    public function test_stripe_connection_mode_is_derived_from_the_configured_key(): void
    {
        Http::fake(['api.stripe.com/v1/account' => Http::response(['id' => 'acct_live', 'country' => 'DE'])]);

        config(['services.stripe.secret' => 'sk_live_example']);
        $this->assertTrue(app(StripeService::class)->testConnection()['livemode']);

        config(['services.stripe.secret' => 'sk_test_example']);
        $this->assertFalse(app(StripeService::class)->testConnection()['livemode']);
    }

    public function test_stripe_setup_only_checks_connection_without_remote_mutations(): void
    {
        config([
            'services.stripe.secret' => 'sk_live_example',
            'services.stripe.webhook_secret' => '',
        ]);
        Http::fake(['api.stripe.com/v1/account' => Http::response(['id' => 'acct_live', 'country' => 'DE'])]);

        $this->artisan('lookdo:stripe:setup')
            ->expectsOutputToContain('Stripe connected: acct_live (live).')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET' && str_ends_with($request->url(), '/v1/account'));
    }

    public function test_sms_settings_are_encrypted_and_never_returned_to_the_admin_client(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $settings = $this->actingAs($admin)->getJson('/api/control/settings')->assertOk()->json('settings');
        $settings['integrations'] = ['stripe' => true, 'openai' => true, 'sms' => true];
        $settings['sms_seven_api_key'] = 'seven-api-secret';
        $settings['sms_seven_signing_key'] = 'seven-signing-secret';

        $this->actingAs($admin)->putJson('/api/control/settings', ['settings' => $settings])
            ->assertOk()
            ->assertJsonPath('sms.api_key_configured', true)
            ->assertJsonPath('sms.signing_key_configured', true)
            ->assertJsonMissing(['sms_seven_api_key' => 'seven-api-secret']);

        $this->assertSame('seven-api-secret', SystemSetting::readSecret('sms_seven_api_key'));
        $this->assertSame('seven-signing-secret', SystemSetting::readSecret('sms_seven_signing_key'));
        $this->assertNotSame('seven-api-secret', SystemSetting::where('key', 'sms_seven_api_key')->firstOrFail()->value);
    }

    public function test_sms_service_enforces_plan_limit_and_records_provider_cost(): void
    {
        Queue::fake();
        Http::fake(['gateway.seven.io/api/sms' => Http::response([
            'success' => '100',
            'total_price' => 0.075,
            'messages' => [['id' => 'provider-message-1', 'parts' => 1, 'price' => 0.075, 'success' => true]],
        ])]);
        SystemSetting::updateOrCreate(['key' => 'integrations'], ['value' => ['stripe' => true, 'openai' => true, 'sms' => true]]);
        SystemSetting::writeSecret('sms_seven_api_key', 'test-api-key');

        $plan = Plan::where('code', 'start')->firstOrFail();
        $plan->entitlements()->updateOrCreate(['key' => 'sms_enabled'], ['value' => '1']);
        $plan->entitlements()->updateOrCreate(['key' => 'sms_monthly_limit'], ['value' => '1']);
        $tenant = Tenant::create(['name' => 'SMS Client', 'slug' => 'sms-client', 'country' => 'DE', 'locale' => 'de', 'status' => 'active']);
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => 'manual', 'status' => 'active', 'started_at' => now()]);

        $sms = app(SmsService::class)->queueImportant($tenant, '0151 12345678', 'Ihre Anfrage ist eingegangen.', 'request_received', 'request-1-received');
        $duplicate = app(SmsService::class)->queueImportant($tenant, '0151 12345678', 'Ihre Anfrage ist eingegangen.', 'request_received', 'request-1-received');
        $this->assertSame($sms->id, $duplicate->id);
        $this->assertSame('+4915112345678', $sms->recipient);
        $this->assertSame(
            'Ihre Anfrage bei SMS Client ist eingegangen. Status: https://sms-client.'.config('tenancy.platform_domain').'/activity',
            app(SmsService::class)->localizedMessage($tenant, 'de', 'request_received'),
        );
        Queue::assertPushed(SendSmsMessage::class, 1);

        (new SendSmsMessage($sms->id))->handle(app(SmsGateway::class));
        $this->assertDatabaseHas('sms_messages', [
            'id' => $sms->id,
            'status' => 'accepted',
            'provider_message_id' => 'provider-message-1',
            'parts' => 1,
            'cost' => 0.075,
        ]);

        try {
            app(SmsService::class)->queueImportant($tenant, '+4915112345678', 'Ihre Arbeit ist fertig.', 'work_ready', 'request-1-ready');
            $this->fail('The strict monthly SMS limit was not enforced.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('limit', $exception->getMessage());
        }

        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->getJson('/api/control/sms')
            ->assertOk()
            ->assertJsonPath('data.0.recipient_masked', '•••• 5678')
            ->assertJsonPath('summary.cost', 0.075);
    }

    public function test_signed_seven_delivery_report_updates_sms_journal(): void
    {
        SystemSetting::writeSecret('sms_seven_signing_key', 'test-signing-key');
        $tenant = Tenant::create(['name' => 'DLR Client', 'slug' => 'dlr-client', 'country' => 'DE', 'locale' => 'de', 'status' => 'active']);
        $sms = SmsMessage::create([
            'tenant_id' => $tenant->id,
            'uuid' => (string) Str::uuid(),
            'provider' => 'seven',
            'event_type' => 'work_ready',
            'recipient' => '+4915112345678',
            'recipient_hash' => hash('sha256', '+4915112345678'),
            'message' => 'Ihre Arbeit ist fertig.',
            'provider_message_id' => 'provider-message-2',
            'status' => 'accepted',
        ]);
        $body = json_encode([
            'webhook_event' => 'dlr',
            'data' => ['msg_id' => 'provider-message-2', 'status' => 'DELIVERED', 'timestamp' => now()->toIso8601String()],
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $nonce = 'sms-test-nonce';
        $target = rtrim((string) config('app.url'), '/').'/api/webhooks/seven/sms';
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, 'POST', $target, md5($body)]), 'test-signing-key');

        $this->call('POST', '/api/webhooks/seven/sms', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
        ], $body)->assertOk()->assertJsonPath('matched', true);

        $this->assertSame('delivered', $sms->fresh()->status);
        $this->assertSame('DELIVERED', $sms->fresh()->provider_status);
        $this->assertNotNull($sms->fresh()->delivered_at);
    }

    public function test_public_and_tenant_pages_render_server_side_social_metadata(): void
    {
        SystemSetting::updateOrCreate(['key' => 'social_share_image_url'], ['value' => '/brand/lookdo-service-workspace.webp']);
        SystemSetting::updateOrCreate(['key' => 'social_share_images'], ['value' => [
            'de' => '/brand/lookdo-social-de.jpg',
            'en' => '/brand/lookdo-social-en.jpg',
            'ru' => '/brand/lookdo-social-ru.jpg',
            'uk' => '/brand/lookdo-social-uk.jpg',
        ]]);
        foreach (['de', 'en', 'ru', 'uk'] as $locale) {
            $this->get('/'.$locale)->assertOk()
                ->assertSee('property="og:image"', false)
                ->assertSee('/brand/lookdo-social-'.$locale.'.jpg', false)
                ->assertSee('name="twitter:card" content="summary_large_image"', false);
        }

        $tenant = Tenant::create(['name' => 'Golden Wheel', 'slug' => 'golden-wheel', 'country' => 'DE', 'locale' => 'de', 'status' => 'active', 'business_description' => 'Lenkräder neu beziehen']);
        $tenant->profile()->create(['social_image_path' => 'tenant-social/'.$tenant->id.'/share.webp', 'social_image_source' => 'upload']);

        $this->get('https://golden-wheel.lookdo.app/de')->assertOk()
            ->assertSee('<meta property="og:title" content="Golden Wheel">', false)
            ->assertSee('<meta property="og:site_name" content="Golden Wheel">', false)
            ->assertSee('https://golden-wheel.lookdo.app/storage/tenant-social/'.$tenant->id.'/share.webp', false);

        $tenant->profile->update([
            'social_image_path' => null,
            'content' => ['branding' => [
                'horizontal_logo_path' => '/brand/tenants/golden-wheel/wide-logo.webp',
                'description_translations' => ['de' => 'Handgefertigte Lenkräder aus der eigenen Werkstatt.'],
            ]],
        ]);

        $this->get('https://golden-wheel.lookdo.app/de')->assertOk()
            ->assertSee('https://golden-wheel.lookdo.app/brand/tenants/golden-wheel/wide-logo.webp', false)
            ->assertSee('Handgefertigte Lenkräder aus der eigenen Werkstatt.', false)
            ->assertDontSee('lookdo-social-de.jpg', false);
    }

    public function test_unpaid_tenant_cannot_use_ai_images_or_add_a_custom_domain(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Unpaid Start', 'slug' => 'unpaid-start', 'country' => 'DE', 'locale' => 'ru', 'status' => 'active', 'business_description' => 'перетяжка рулей автомобилей']);
        $tenant->users()->attach($owner, ['role' => 'owner']);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => 'stripe', 'status' => 'incomplete', 'billing_cycle' => 'monthly', 'currency' => 'EUR', 'unit_amount' => 19, 'started_at' => now()]);

        $this->actingAs($owner)->getJson('/api/tenant/'.$tenant->id)
            ->assertOk()
            ->assertJsonPath('entitlements.custom_domain', '0')
            ->assertJsonPath('image_generation.can_generate', false)
            ->assertJsonPath('image_generation.payment_required', true);

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/social-image/prompt')
            ->assertStatus(402)->assertJsonPath('message', 'SUBSCRIPTION_PAYMENT_REQUIRED');
        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/social-image/generate', [
            'prompt' => 'Реалистичная фотография мастерской по перетяжке автомобильных рулей без текста и логотипов.',
        ])->assertStatus(402)->assertJsonPath('message', 'SUBSCRIPTION_PAYMENT_REQUIRED');
        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/image-credits/checkout', ['quantity' => 1])
            ->assertStatus(402)->assertJsonPath('message', 'SUBSCRIPTION_PAYMENT_REQUIRED');
        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/domains', ['domain' => 'unpaid-start.de'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_tenant_reviews_an_ai_prompt_before_social_image_generation(): void
    {
        Storage::fake('public');
        config(['services.openai.key' => 'test-key', 'services.openai.image_model' => 'gpt-image-2', 'services.openai.image_cost_medium' => .053]);
        $reviewedPrompt = 'Реалистичная премиальная фотография автосервиса: мастер точно устанавливает автомобильную дверь на кузов, рядом видны специальные инструменты, горизонтальная композиция, без текста и логотипов.';
        Http::fake(function (HttpRequest $request) use ($reviewedPrompt) {
            if ($request->url() === 'https://api.openai.com/v1/responses') {
                return Http::response(['output_text' => json_encode(['prompt' => $reviewedPrompt]), 'model' => 'gpt-5.6-luna', 'usage' => ['input_tokens' => 120, 'output_tokens' => 45]]);
            }

            return Http::response(['data' => [['b64_json' => base64_encode('generated-webp')]]]);
        });
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Auto Door Pro', 'slug' => 'auto-door-pro', 'country' => 'DE', 'locale' => 'ru', 'status' => 'active', 'business_description' => 'я устанавливаю двери']);
        $tenant->users()->attach($owner, ['role' => 'owner']);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => 'stripe', 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'EUR', 'unit_amount' => 19, 'started_at' => now()]);
        $variation = BusinessVariation::where('code', 'automotive.general')->firstOrFail();
        $template = RequestTemplate::where('code', 'automotive.general')->firstOrFail();
        $tenant->businessProfile()->create(['category_id' => $variation->category_id, 'variation_id' => $variation->id, 'request_template_id' => $template->id, 'original_description' => $tenant->business_description]);

        $upload = $this->actingAs($owner)->post('/api/tenant/'.$tenant->id.'/social-image', [
            'image' => UploadedFile::fake()->image('share.jpg', 1200, 630),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('social_image_source', 'upload');

        $prompt = $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/social-image/prompt')
            ->assertOk()
            ->assertJsonPath('prompt', $reviewedPrompt)
            ->assertJsonPath('context.business_description', 'я устанавливаю двери')
            ->assertJsonPath('image_generation.remaining_free', 3);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.openai.com/v1/responses'
            && str_contains((string) $request['input'], 'я устанавливаю двери')
            && str_contains((string) $request['input'], 'automotive')
            && str_contains((string) $request['instructions'], 'Russian'));

        $generated = $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/social-image/generate', ['prompt' => $prompt->json('prompt')])
            ->assertCreated()->assertJsonPath('social_image_source', 'ai')->assertJsonPath('image_generation.remaining_free', 2);
        Storage::disk('public')->assertExists($generated->json('social_image_path'));
        Storage::disk('public')->assertMissing($upload->json('social_image_path'));
        $this->assertDatabaseHas('tenant_profiles', ['tenant_id' => $tenant->id, 'social_image_source' => 'ai', 'image_generation_free_used' => 1]);
        $this->assertDatabaseHas('ai_usage_records', ['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'operation' => 'tenant_social_image_generation', 'model' => 'gpt-image-2', 'cost' => .053]);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.openai.com/v1/images/generations'
            && $request['prompt'] === $reviewedPrompt
            && $request['model'] === 'gpt-image-2'
            && $request['size'] === '1536x1024'
            && $request['output_format'] === 'webp');
    }

    public function test_image_limit_requires_credit_and_stripe_webhook_adds_it_once(): void
    {
        Storage::fake('public');
        config([
            'services.openai.key' => 'test-key',
            'services.stripe.secret' => 'sk_live_test',
            'services.stripe.webhook_secret' => 'whsec_image_test',
        ]);
        Http::fake(function (HttpRequest $request) {
            if ($request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
                return Http::response(['id' => 'cs_live_image_1', 'url' => 'https://checkout.stripe.test/image-credit']);
            }
            if ($request->url() === 'https://api.openai.com/v1/images/generations') {
                return Http::response(['data' => [['b64_json' => base64_encode('generated-webp')]]]);
            }

            return Http::response([], 404);
        });
        $owner = User::factory()->create();
        $tenant = Tenant::create(['name' => 'Credit Test', 'slug' => 'credit-test', 'country' => 'DE', 'locale' => 'de', 'status' => 'active', 'business_description' => 'Automobile doors professionally installed and repaired']);
        $tenant->users()->attach($owner, ['role' => 'owner']);
        $plan = Plan::where('code', 'start')->firstOrFail();
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => 'stripe', 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'EUR', 'unit_amount' => 19, 'started_at' => now()]);
        DB::table('tenant_entitlement_overrides')->insert([
            ['tenant_id' => $tenant->id, 'key' => 'social_image_free_generations', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'key' => 'social_image_credit_price_cents', 'value' => '150', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $prompt = 'Premium realistic automotive workshop with a technician installing a vehicle door, landscape photography, no text, logos or watermarks.';

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/social-image/generate', ['prompt' => $prompt])
            ->assertCreated()->assertJsonPath('image_generation.remaining_free', 0);
        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/social-image/generate', ['prompt' => $prompt])
            ->assertStatus(402)->assertJsonPath('message', 'IMAGE_CREDIT_REQUIRED');

        $checkout = $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/image-credits/checkout', ['quantity' => 2])
            ->assertOk()->assertJsonPath('checkout_url', 'https://checkout.stripe.test/image-credit');
        $purchaseId = DB::table('image_credit_purchases')->value('id');
        $this->assertDatabaseHas('image_credit_purchases', ['id' => $purchaseId, 'quantity' => 2, 'unit_amount' => 1.50, 'total_amount' => 3.00, 'status' => 'pending']);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['mode'] === 'payment'
            && (int) data_get($request->data(), 'line_items.0.price_data.unit_amount') === 300
            && data_get($request->data(), 'metadata.lookdo_type') === 'image_credit');

        $event = ['id' => 'evt_image_credit_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_live_image_1', 'payment_intent' => 'pi_image_1', 'metadata' => ['lookdo_type' => 'image_credit', 'tenant_id' => (string) $tenant->id, 'purchase_id' => (string) $purchaseId, 'quantity' => '2']]]];
        $payload = json_encode($event, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_image_test');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature];
        $this->call('POST', '/api/stripe/webhook', [], [], [], $server, $payload)->assertOk();
        $this->assertDatabaseHas('tenant_profiles', ['tenant_id' => $tenant->id, 'image_generation_credits' => 2]);
        $this->call('POST', '/api/stripe/webhook', [], [], [], $server, $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(2, (int) $tenant->profile()->value('image_generation_credits'));

        $this->actingAs($owner)->postJson('/api/tenant/'.$tenant->id.'/social-image/generate', ['prompt' => $prompt])
            ->assertCreated()->assertJsonPath('image_generation.credits', 1);
        $this->assertSame(1, (int) $tenant->profile()->value('image_generation_credits'));
    }

    public function test_platform_exposes_configured_demo_video(): void
    {
        SystemSetting::updateOrCreate(['key' => 'demo_video_source'], ['value' => 'youtube']);
        SystemSetting::updateOrCreate(['key' => 'demo_video_url'], ['value' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        $this->getJson('/api/platform')->assertOk()
            ->assertJsonPath('demo_video.source', 'youtube')
            ->assertJsonPath('demo_video.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    }
}
