<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminAuditAndReminderOperationsTest extends TestCase
{
    use RefreshDatabase;

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
}
