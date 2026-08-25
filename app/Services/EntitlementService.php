<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    public function all(Tenant $tenant): array
    {
        $plan = $tenant->currentSubscription?->plan;
        $base = $plan?->entitlements?->pluck('value', 'key')->all() ?? [];
        $overrides = DB::table('tenant_entitlement_overrides')->where('tenant_id', $tenant->id)->pluck('value', 'key')->all();

        return array_replace($base, $overrides);
    }

    public function get(Tenant $tenant, string $key, mixed $default = null): mixed
    {
        return $this->all($tenant)[$key] ?? $default;
    }
}
