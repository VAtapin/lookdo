<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_record_cash_payment_activate_access_and_print_receipt(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $owner = User::factory()->create(['is_active' => true]);
        $tenant = Tenant::create(['name' => 'Testbetrieb', 'slug' => 'testbetrieb']);
        $tenant->users()->attach($owner->id, ['role' => 'owner']);
        $plan = Plan::create(['code' => 'pro', 'name' => ['de' => 'Pro'], 'price_monthly' => 29, 'currency' => 'EUR']);
        $subscription = Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'provider' => 'stripe', 'status' => 'incomplete']);

        $response = $this->actingAs($admin)->postJson('/api/control/subscriptions/'.$subscription->id.'/payments', [
            'amount' => 29,
            'currency' => 'EUR',
            'payment_method' => 'cash',
            'paid_at' => '2026-09-02 12:00:00',
            'reference' => 'Bar erhalten',
            'note' => 'Persönlich übergeben',
            'grant_access' => true,
            'access_until' => '2026-10-02 12:00:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('payments.0.payment_method', 'cash')
            ->assertJsonPath('payments.0.recorded_by.id', $admin->id);

        $payment = $subscription->payments()->firstOrFail();
        $this->assertSame('ZB-2026-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT), $payment->receipt_number);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.payment_recorded_manually', 'tenant_id' => $tenant->id]);

        $this->actingAs($admin)
            ->get('/api/control/subscriptions/'.$subscription->id.'/payments/'.$payment->id.'/receipt')
            ->assertOk()
            ->assertSee('Zahlungsbeleg')
            ->assertSee($payment->receipt_number)
            ->assertSee('Barzahlung');
    }

    public function test_manual_status_change_requires_a_reason_and_is_audited(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $tenant = Tenant::create(['name' => 'Testbetrieb', 'slug' => 'testbetrieb']);
        $plan = Plan::create(['code' => 'pro', 'name' => ['de' => 'Pro']]);
        $subscription = Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'provider' => 'stripe', 'status' => 'past_due']);

        $this->actingAs($admin)->patchJson('/api/control/subscriptions/'.$subscription->id.'/status', ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($admin)->patchJson('/api/control/subscriptions/'.$subscription->id.'/status', [
            'status' => 'active',
            'reason' => 'Barzahlung vor Ort bestätigt',
            'current_period_end' => '2026-10-02 12:00:00',
        ])->assertOk()->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'manual_status_changed_by' => $admin->id,
            'manual_status_reason' => 'Barzahlung vor Ort bestätigt',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.status_changed_manually', 'tenant_id' => $tenant->id]);
    }
}
