<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP zip extension is required.');
        }
        Storage::fake('public');
        $this->backupPath = storage_path('framework/testing/tenant-backups-'.Str::uuid());
        config([
            'backup.tenant_path' => $this->backupPath,
            'backup.tenant_keep' => 14,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->backupPath)) {
            File::deleteDirectory($this->backupPath);
        }
        parent::tearDown();
    }

    public function test_it_fully_restores_only_the_selected_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Client One',
            'slug' => 'client-one',
            'status' => 'active',
            'country' => 'DE',
            'locale' => 'de',
        ]);
        $other = Tenant::create([
            'name' => 'Client Two',
            'slug' => 'client-two',
            'status' => 'active',
            'country' => 'DE',
            'locale' => 'de',
        ]);
        DB::table('tenant_profiles')->insert([
            'tenant_id' => $tenant->id,
            'contact_name' => 'Original contact',
            'primary_color' => '#123456',
            'secondary_color' => '#111111',
            'image_generation_free_used' => 0,
            'image_generation_credits' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $teamUser = User::factory()->create(['email' => 'team@example.test']);
        DB::table('tenant_users')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $teamUser->id,
            'role' => 'manager',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $domainId = DB::table('tenant_domains')->insertGetId([
            'tenant_id' => $tenant->id,
            'domain' => 'client-one.example.test',
            'type' => 'custom',
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tenant->update(['primary_domain_id' => $domainId]);
        $planId = DB::table('plans')->insertGetId([
            'code' => 'backup-test',
            'name' => json_encode(['de' => 'Test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'tenant_id' => $tenant->id,
            'plan_id' => $planId,
            'provider' => 'manual',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('subscription_payments')->insertGetId([
            'subscription_id' => $subscriptionId,
            'amount' => 25,
            'currency' => 'EUR',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $serviceId = DB::table('tenant_services')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => json_encode(['de' => 'Original']),
            'duration_minutes' => 45,
            'currency' => 'EUR',
            'booking_enabled' => true,
            'media_allowed' => true,
            'active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherServiceId = DB::table('tenant_services')->insertGetId([
            'tenant_id' => $other->id,
            'name' => json_encode(['de' => 'Other original']),
            'duration_minutes' => 60,
            'currency' => 'EUR',
            'booking_enabled' => true,
            'media_allowed' => true,
            'active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Storage::disk('public')->put("tenant-app/{$tenant->id}/portfolio/work.jpg", 'original-file');

        $backups = app(TenantBackupService::class);
        $snapshot = $backups->create($tenant);

        DB::table('tenant_services')->where('id', $serviceId)->update(['name' => json_encode(['de' => 'Damaged'])]);
        DB::table('tenant_services')->where('id', $otherServiceId)->update(['name' => json_encode(['de' => 'Other changed'])]);
        $tenant->update(['name' => 'Damaged client', 'slug' => 'damaged-client', 'status' => 'suspended', 'manual_access_until' => now()->addMonth()]);
        DB::table('tenant_profiles')->where('tenant_id', $tenant->id)->update([
            'contact_name' => 'Damaged contact',
            'image_generation_credits' => 9,
        ]);
        DB::table('tenant_users')->where('tenant_id', $tenant->id)->update(['role' => 'viewer']);
        DB::table('tenants')->where('id', $tenant->id)->update(['primary_domain_id' => null]);
        DB::table('tenant_domains')->where('id', $domainId)->delete();
        DB::table('subscription_payments')->where('id', $paymentId)->update(['status' => 'failed']);
        Storage::disk('public')->put("tenant-app/{$tenant->id}/portfolio/work.jpg", 'damaged-file');

        $result = $backups->restore($tenant->fresh(), $snapshot['name']);

        $this->assertTrue($result['restored']);
        $this->assertNotEmpty($result['safety_backup']);
        $this->assertSame(['de' => 'Original'], json_decode(DB::table('tenant_services')->where('id', $serviceId)->value('name'), true));
        $this->assertSame(['de' => 'Other changed'], json_decode(DB::table('tenant_services')->where('id', $otherServiceId)->value('name'), true));
        $this->assertSame('original-file', Storage::disk('public')->get("tenant-app/{$tenant->id}/portfolio/work.jpg"));
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Client One',
            'slug' => 'client-one',
            'status' => 'active',
        ]);
        $this->assertNull(Tenant::findOrFail($tenant->id)->manual_access_until);
        $this->assertDatabaseHas('tenant_profiles', [
            'tenant_id' => $tenant->id,
            'contact_name' => 'Original contact',
            'image_generation_credits' => 4,
        ]);
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'user_id' => $teamUser->id,
            'role' => 'manager',
        ]);
        $this->assertDatabaseHas('tenant_domains', [
            'id' => $domainId,
            'tenant_id' => $tenant->id,
            'domain' => 'client-one.example.test',
        ]);
        $this->assertSame($domainId, Tenant::findOrFail($tenant->id)->primary_domain_id);
        $this->assertDatabaseHas('subscription_payments', ['id' => $paymentId, 'status' => 'paid']);
        $this->assertCount(2, $backups->list($tenant));
    }
}
