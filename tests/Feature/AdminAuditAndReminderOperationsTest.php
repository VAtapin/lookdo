<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminAuditAndReminderOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_audits_include_names_and_can_be_filtered_by_actor_and_tenant(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $master = User::factory()->create(['name' => 'Marta Meister', 'email' => 'marta@example.test']);
        $otherMaster = User::factory()->create(['name' => 'Andere Meisterin']);
        $tenant = Tenant::create(['name' => 'Salon Morgen', 'slug' => 'salon-morgen', 'status' => 'active']);
        $otherTenant = Tenant::create(['name' => 'Salon Abend', 'slug' => 'salon-abend', 'status' => 'active']);
        AuditLog::create(['action' => 'tenant.request.updated', 'actor_id' => $master->id, 'tenant_id' => $tenant->id]);
        AuditLog::create(['action' => 'tenant.request.updated', 'actor_id' => $otherMaster->id, 'tenant_id' => $otherTenant->id]);

        $this->actingAs($admin)->getJson("/api/control/audits?actor_id={$master->id}&tenant_id={$tenant->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor.name', 'Marta Meister')
            ->assertJsonPath('data.0.actor.email', 'marta@example.test')
            ->assertJsonPath('data.0.tenant.name', 'Salon Morgen')
            ->assertJsonPath('data.0.tenant.slug', 'salon-morgen')
            ->assertJsonFragment(['name' => 'Andere Meisterin'])
            ->assertJsonFragment(['name' => 'Salon Abend']);

        $this->actingAs($admin)->getJson('/api/control/audits?search=Morgen')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_super_admin_can_prune_old_audit_entries_without_removing_recent_entries(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $old = AuditLog::create(['action' => 'old.entry', 'created_at' => now()->subDays(120), 'updated_at' => now()->subDays(120)]);
        $recent = AuditLog::create(['action' => 'recent.entry']);

        $this->actingAs($admin)->deleteJson('/api/control/audits', [
            'scope' => 'older',
            'days' => 90,
        ])->assertOk()->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'audit.cleared', 'actor_id' => $admin->id]);
    }

    public function test_clearing_all_audits_requires_explicit_confirmation(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        AuditLog::create(['action' => 'existing.entry']);

        $this->actingAs($admin)->deleteJson('/api/control/audits', ['scope' => 'all'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->actingAs($admin)->deleteJson('/api/control/audits', [
            'scope' => 'all',
            'confirmation' => 'PRÜFPROTOKOLL LÖSCHEN',
        ])->assertOk()->assertJsonPath('deleted', 1);

        $this->assertSame(['audit.cleared'], AuditLog::pluck('action')->all());
    }

    public function test_reminder_dispatch_command_records_a_server_heartbeat(): void
    {
        $this->assertSame(0, Artisan::call('lookdo:reminders:send'));

        $status = (array) SystemSetting::read('reminder_dispatch_status', []);
        $this->assertNotEmpty($status['last_finished_at'] ?? null);
        $this->assertSame(0, $status['last_result']['processed'] ?? null);
        $this->assertNotEmpty(data_get(SystemSetting::read('queue_worker_heartbeat', []), 'last_run_at'));
    }

    public function test_sms_connection_check_returns_an_actionable_validation_error_when_disabled(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/control/sms/test')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'SMS-Versand ist deaktiviert. Aktivieren und speichern Sie zuerst die globale SMS-Freigabe.');
    }
}
