<?php

namespace App\Services;

use App\Models\TenantDomain;

class DomainService
{
    public function verify(TenantDomain $domain): TenantDomain
    {
        if ($domain->type === 'platform') {
            return $domain;
        }
        $domain->forceFill(['status' => 'verifying', 'last_checked_at' => now(), 'last_error' => null])->save();
        $target = parse_url(config('app.url'), PHP_URL_HOST) ?: config('tenancy.platform_domain');
        $ips = array_values(array_unique(array_filter(array_merge(gethostbynamel($domain->domain) ?: [], gethostbynamel($target) ?: []))));
        $domainIps = gethostbynamel($domain->domain) ?: [];
        $targetIps = gethostbynamel($target) ?: [];
        if (array_intersect($domainIps, $targetIps)) {
            $domain->update(['status' => 'ssl_pending', 'verified_at' => now(), 'ssl_status' => 'pending']);
        } else {
            $domain->update(['status' => 'failed', 'last_error' => 'DNS does not point to LOOKDO yet.']);
        }

        return $domain->refresh();
    }
}
