<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminTenantManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_super_admin_can_manage_an_owner_through_the_tenant_resource(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        [$tenant, $owner] = $this->tenantWithOwner();

        $this->actingAs($admin)
            ->putJson("/api/control/tenants/{$tenant->id}/owner", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('id', $owner->id)
            ->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'is_active' => false]);
    }

    public function test_super_admin_can_permanently_delete_an_unpaid_client_and_its_files(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_super_admin' => true]);
        [$tenant, $owner] = $this->tenantWithOwner();
        $tenant->profile()->create([
            'email' => $owner->email,
            'social_image_path' => "tenant-social/{$tenant->id}/share.webp",
        ]);
        $domain = $tenant->domains()->create([
            'domain' => $tenant->slug.'.lookdo.app', 'type' => 'platform', 'status' => 'active',
        ]);
        $tenant->update(['primary_domain_id' => $domain->id]);
        $tenant->subscriptions()->create([
            'plan_id' => Plan::where('code', 'start')->value('id'),
            'provider' => 'stripe', 'status' => 'incomplete', 'started_at' => now(),
        ]);
        $service = $tenant->services()->create([
            'name' => ['de' => 'Terminservice'],
            'duration_minutes' => 60,
        ]);
        $appointment = $tenant->appointments()->create([
            'service_id' => $service->id,
            'number' => 'A-DELETE-1',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        Storage::disk('public')->put("tenant-social/{$tenant->id}/share.webp", 'image');
        Storage::disk('public')->put("tenant-app/{$tenant->id}/requests/1/photo.webp", 'image');

        $this->actingAs($admin)
            ->deleteJson("/api/control/tenants/{$tenant->id}", ['confirmed' => true])
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseMissing('tenant_appointments', ['id' => $appointment->id]);
        $this->assertDatabaseMissing('tenant_services', ['id' => $service->id]);
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.deleted']);
        Storage::disk('public')->assertMissing("tenant-social/{$tenant->id}");
        Storage::disk('public')->assertMissing("tenant-app/{$tenant->id}");
    }

    public function test_deleting_a_client_preserves_an_owner_used_by_another_client(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_super_admin' => true]);
        [$tenant, $owner] = $this->tenantWithOwner();
        $other = Tenant::create([
            'name' => 'Other client', 'slug' => 'other-client', 'country' => 'DE',
            'locale' => 'de', 'status' => 'active',
        ]);
        $other->users()->attach($owner, ['role' => 'owner']);

        $this->actingAs($admin)
            ->deleteJson("/api/control/tenants/{$tenant->id}", ['confirmed' => true])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $other->id, 'user_id' => $owner->id]);
    }

    public function test_client_with_a_live_stripe_subscription_cannot_be_deleted_locally(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_super_admin' => true]);
        [$tenant, $owner] = $this->tenantWithOwner();
        $tenant->subscriptions()->create([
            'plan_id' => Plan::where('code', 'start')->value('id'),
            'provider' => 'stripe', 'provider_subscription_id' => 'sub_live',
            'status' => 'active', 'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/control/tenants/{$tenant->id}", ['confirmed' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant');

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_new_tenant_controller_covers_the_existing_management_workflow(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        [$tenant] = $this->tenantWithOwner();
        $plan = Plan::where('code', 'start')->firstOrFail();
        $tenant->subscriptions()->create([
            'plan_id' => $plan->id, 'provider' => 'stripe', 'status' => 'incomplete', 'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/control/tenants?search='.$tenant->slug)
            ->assertOk()
            ->assertJsonPath('data.0.id', $tenant->id);

        $this->actingAs($admin)
            ->getJson("/api/control/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('id', $tenant->id);

        $this->actingAs($admin)
            ->putJson("/api/control/tenants/{$tenant->id}", ['name' => 'Updated client'])
            ->assertOk();
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Updated client']);

        $this->actingAs($admin)
            ->postJson("/api/control/tenants/{$tenant->id}/grant-access", ['days' => 5])
            ->assertOk()
            ->assertJsonPath('tenant.id', $tenant->id);

        $this->actingAs($admin)
            ->putJson("/api/control/tenants/{$tenant->id}/entitlement", ['key' => 'custom_flag', 'value' => '1'])
            ->assertOk();
        $this->assertDatabaseHas('tenant_entitlement_overrides', [
            'tenant_id' => $tenant->id, 'key' => 'custom_flag', 'value' => '1',
        ]);

        $email = 'created-'.str()->random(8).'@example.com';
        $created = $this->actingAs($admin)
            ->postJson('/api/control/tenants', [
                'name' => 'Created client',
                'owner_name' => 'Created Owner',
                'owner_email' => $email,
                'owner_password' => 'SecurePass123',
                'country' => 'DE',
                'locale' => 'de',
                'plan_id' => $plan->id,
            ])
            ->assertCreated();
        $this->assertDatabaseHas('tenants', ['id' => $created->json('id'), 'name' => 'Created client']);

        $this->actingAs($admin)
            ->postJson("/api/control/tenants/{$tenant->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id);
    }

    public function test_super_admin_can_manage_customer_sms_entitlements_without_technical_fields(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        [$tenant] = $this->tenantWithOwner();
        $tenant->subscriptions()->create([
            'plan_id' => Plan::where('code', 'start')->value('id'),
            'provider' => 'stripe',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->putJson("/api/control/tenants/{$tenant->id}/entitlement", [
                'overrides' => [
                    ['key' => 'sms_enabled', 'value' => '1'],
                    ['key' => 'sms_monthly_limit', 'value' => '50'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('overrides.sms_enabled', '1')
            ->assertJsonPath('overrides.sms_monthly_limit', '50');

        $this->actingAs($admin)
            ->getJson("/api/control/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('entitlements.sms_enabled', '1')
            ->assertJsonPath('entitlements.sms_monthly_limit', '50')
            ->assertJsonPath('entitlement_overrides.sms_enabled', '1');

        $this->actingAs($admin)
            ->deleteJson("/api/control/tenants/{$tenant->id}/entitlement", [
                'keys' => ['sms_enabled', 'sms_monthly_limit'],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('tenant_entitlement_overrides', [
            'tenant_id' => $tenant->id,
            'key' => 'sms_enabled',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'tenant.entitlement.cleared',
        ]);
    }

    /** @return array{Tenant, User} */
    private function tenantWithOwner(): array
    {
        $owner = User::factory()->create(['is_super_admin' => false]);
        $tenant = Tenant::create([
            'name' => 'Delete me', 'slug' => 'delete-me-'.str()->random(6),
            'country' => 'DE', 'locale' => 'de', 'status' => 'active',
        ]);
        $tenant->users()->attach($owner, ['role' => 'owner']);

        return [$tenant, $owner];
    }
}
