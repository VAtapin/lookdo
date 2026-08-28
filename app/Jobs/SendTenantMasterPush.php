<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\TenantWebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTenantMasterPush implements ShouldQueue
{
    use Queueable;

    /** @param array{title:string,body:string,url:string,tag?:string,action?:string} $payload */
    public function __construct(public int $tenantId, public array $payload) {}

    public function handle(TenantWebPushService $webPush): void
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant) {
            $webPush->sendToTenantUsers($tenant, $this->payload);
        }
    }
}
