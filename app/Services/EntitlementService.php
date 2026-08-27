<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    private ?array $fullTrialEntitlements = null;

    public function all(Tenant $tenant): array
    {
        $subscription = $tenant->currentSubscription;
        $base = $subscription?->isTrialActive()
            ? $this->fullTrialEntitlements()
            : ($subscription?->plan?->entitlements?->pluck('value', 'key')->all() ?? []);
        $overrides = DB::table('tenant_entitlement_overrides')->where('tenant_id', $tenant->id)->pluck('value', 'key')->all();

        return array_replace($base, $overrides);
    }

    public function get(Tenant $tenant, string $key, mixed $default = null): mixed
    {
        return $this->all($tenant)[$key] ?? $default;
    }

    private function fullTrialEntitlements(): array
    {
        if ($this->fullTrialEntitlements !== null) {
            return $this->fullTrialEntitlements;
        }

        $this->fullTrialEntitlements = [];
        Plan::query()
            ->with('entitlements')
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('price_monthly')
            ->get()
            ->each(function (Plan $plan): void {
                $this->fullTrialEntitlements = array_replace(
                    $this->fullTrialEntitlements,
                    $plan->entitlements->pluck('value', 'key')->all(),
                );
            });

        return $this->fullTrialEntitlements;
    }
}
