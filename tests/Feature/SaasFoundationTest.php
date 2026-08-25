<?php

namespace Tests\Feature;

use App\Models\BusinessClassification;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertOk()->assertJsonPath('data.0.variation.code','repair-finishing-installation.door-installation');
    }
}
