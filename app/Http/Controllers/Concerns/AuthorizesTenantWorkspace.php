<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Tenant;
use Illuminate\Http\Request;

trait AuthorizesTenantWorkspace
{
    private function authorizeWorkspace(Request $request, Tenant $tenant): void
    {
        abort_unless($request->user()?->is_super_admin || $request->user()?->tenants()->whereKey($tenant->id)->exists(), 403);
        abort_unless($tenant->hasActiveSubscription(), 402, 'SUBSCRIPTION_PAYMENT_REQUIRED');
    }
}
