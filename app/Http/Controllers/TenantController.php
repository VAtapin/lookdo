<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\AuditService;
use App\Services\DomainService;
use App\Services\EntitlementService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class TenantController extends Controller
{
    private function authorizeTenant(Request $request, Tenant $tenant): void
    {
        abort_unless($request->user()->is_super_admin || $request->user()->tenants()->whereKey($tenant->id)->exists(), 403);
    }

    public function show(Request $request, Tenant $tenant, EntitlementService $entitlements): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $tenant->load(['profile', 'domains', 'currentSubscription.plan.entitlements', 'currentSubscription.payments', 'businessProfile.category', 'businessProfile.variation', 'businessProfile.template']);

        return response()->json(['tenant' => $tenant, 'entitlements' => $entitlements->all($tenant), 'platform_url' => 'https://'.$tenant->slug.'.'.config('tenancy.platform_domain')]);
    }

    public function updateProfile(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['name' => 'required|string|max:160', 'contact_name' => 'nullable|string|max:120', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:50', 'street' => 'nullable|string|max:160', 'postal_code' => 'nullable|string|max:30', 'city' => 'nullable|string|max:100', 'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $before = $tenant->load('profile')->toArray();
        $tenant->update(['name' => $data['name']]);
        unset($data['name']);
        $tenant->profile()->updateOrCreate([], $data);
        $audit->log('tenant.profile.updated', $tenant, $before, $tenant->fresh('profile')->toArray(), $tenant->id);

        return response()->json(['tenant' => $tenant->fresh('profile')]);
    }

    public function updateSlug(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['slug' => ['required', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', Rule::unique('tenants', 'slug')->ignore($tenant->id), Rule::notIn(config('tenancy.reserved_slugs'))]]);
        abort_if($tenant->domains()->where('type', 'custom')->where('status', 'active')->exists(), 422, 'Contact support to change a live slug.');
        $before = $tenant->slug;
        DB::transaction(function () use ($tenant, $data) {
            $tenant->update(['slug' => $data['slug']]);
            $domain = $tenant->domains()->where('type', 'platform')->first();
            $domain?->update(['domain' => $data['slug'].'.'.config('tenancy.platform_domain')]);
        });
        $audit->log('tenant.slug.updated', $tenant, ['slug' => $before], ['slug' => $data['slug']], $tenant->id);

        return response()->json(['tenant' => $tenant->fresh('domains')]);
    }

    public function addDomain(Request $request, Tenant $tenant, EntitlementService $entitlements, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless(filter_var($entitlements->get($tenant, 'custom_domain', 0), FILTER_VALIDATE_BOOLEAN), 403, 'Your plan does not include a custom domain.');
        $data = $request->validate(['domain' => 'required|string|max:253']);
        $domain = strtolower(trim(preg_replace('#^https?://#', '', $data['domain']), "/ \t\n\r\0\x0B"));
        abort_if(str_contains($domain, '/'), 422, 'Enter a hostname without a path.');
        $request->merge(['domain' => $domain]);
        validator(['domain' => $domain], ['domain' => ['required', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', Rule::unique('tenant_domains', 'domain')]])->validate();
        $record = $tenant->domains()->create(['domain' => $domain, 'type' => 'custom', 'status' => 'pending', 'verification_token' => Str::random(40)]);
        $audit->log('domain.created', $record, null, $record->toArray(), $tenant->id);

        return response()->json(['domain' => $record, 'dns' => ['type' => 'A/AAAA or CNAME', 'target' => parse_url(config('app.url'), PHP_URL_HOST)]], 201);
    }

    public function verifyDomain(Request $request, Tenant $tenant, TenantDomain $domain, DomainService $service, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless($domain->tenant_id === $tenant->id, 404);
        $before = $domain->toArray();
        $domain = $service->verify($domain);
        $audit->log('domain.verified', $domain, $before, $domain->toArray(), $tenant->id);

        return response()->json(['domain' => $domain]);
    }

    public function removeDomain(Request $request, Tenant $tenant, TenantDomain $domain, AuditService $audit): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        abort_unless($domain->tenant_id === $tenant->id && $domain->type === 'custom' && $domain->status !== 'active', 422);
        $before = $domain->toArray();
        $domain->delete();
        $audit->log('domain.deleted', null, $before, null, $tenant->id);

        return response()->json(['ok' => true]);
    }

    public function checkout(Request $request, Tenant $tenant, StripeService $stripe): JsonResponse
    {
        $this->authorizeTenant($request, $tenant);
        $data = $request->validate(['plan_id' => 'required|exists:plans,id', 'cycle' => ['required', Rule::in(['monthly', 'yearly'])]]);
        $plan = Plan::where('is_active', true)->findOrFail($data['plan_id']);
        $subscription = $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => 'stripe', 'status' => 'incomplete', 'started_at' => now()]);
        try {
            $url = $stripe->checkout($tenant,$plan,$request->user()->email,$data['cycle']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()],422);
        }

return response()->json(['checkout_url' => $url]);
    }
}
