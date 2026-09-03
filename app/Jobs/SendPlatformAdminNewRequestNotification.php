<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Services\PlatformAdminNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPlatformAdminNewRequestNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $tenantRequestId,
    ) {}

    public function handle(PlatformAdminNotificationService $notifications): void
    {
        $tenant = Tenant::find($this->tenantId);
        $tenantRequest = TenantRequest::find($this->tenantRequestId);

        if ($tenant && $tenantRequest && $tenantRequest->tenant_id === $tenant->id) {
            $notifications->newRequest($tenant, $tenantRequest);
        }
    }
}
