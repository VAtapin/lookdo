<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $request->getHost()));
        $base = strtolower((string) config('tenancy.platform_domain'));
        try {
            $tenant = null;
            if ($host !== $base && $host !== "www.$base" && $host !== 'localhost' && $host !== '127.0.0.1') {
                if (str_ends_with($host, ".$base")) {
                    $slug = substr($host, 0, -strlen(".$base"));
                    if (! str_contains($slug, '.')) {
                        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();
                    }
                } else {
                    $domain = TenantDomain::with('tenant')->where('domain', $host)->where('status', 'active')->first();
                    $tenant = $domain?->tenant?->status === 'active' ? $domain->tenant : null;
                }
            }
            $this->current->set($tenant);
            if ($tenant) {
                $request->attributes->set('tenant', $tenant);
            }
        } catch (QueryException) { /* Allows installer and health screen before first migration. */
        }

        return $next($request);
    }
}
