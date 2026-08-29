<?php

namespace App\Http\Controllers;

use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\RequestTemplate;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AdminTenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $owner = fn ($query) => $query->wherePivot('role', 'owner')->select('users.id', 'users.name', 'users.email', 'users.is_active', 'users.last_login_at');
        $q = Tenant::with(['users' => $owner, 'primaryDomain', 'currentSubscription.plan', 'businessProfile.category', 'businessProfile.variation']);
        if ($s = $request->string('search')->trim()->toString()) {
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")
                ->orWhere('slug', 'like', "%$s%")
                ->orWhereHas('users', fn ($user) => $user->where('tenant_users.role', 'owner')->where(fn ($owner) => $owner->where('users.name', 'like', "%$s%")->orWhere('users.email', 'like', "%$s%")))
                ->orWhereHas('domains', fn ($domain) => $domain->where('domain', 'like', "%$s%")));
        } if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        $sort = $this->sortColumn($request, ['name', 'slug', 'status', 'created_at', 'last_activity_at']);

        return response()->json($q->orderBy($sort, $this->sortDirection($request))->paginate($this->perPage($request)));
    }

    public function store(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:160', 'slug' => 'nullable|string|max:63|unique:tenants,slug',
            'owner_name' => 'required|string|max:120', 'owner_email' => 'required|email|max:255|unique:users,email',
            'owner_password' => ['required', Password::min(10)->letters()->numbers()],
            'country' => 'nullable|string|size:2', 'locale' => ['nullable', Rule::in(['de', 'en', 'ru', 'uk'])],
            'business_description' => 'nullable|string|max:1000', 'variation_id' => 'nullable|exists:business_variations,id',
            'plan_id' => 'required|exists:plans,id', 'complimentary' => 'nullable|boolean',
            'complimentary_days' => 'nullable|integer|min:1|max:3650',
        ]);
        $plan = Plan::findOrFail($data['plan_id']);
        $tenant = DB::transaction(function () use ($data, $plan) {
            $slug = $this->uniqueTenantSlug($data['slug'] ?? $data['name']);
            $user = User::create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $data['owner_password'], 'locale' => $data['locale'] ?? 'ru', 'is_active' => true]);
            $tenant = Tenant::create(['name' => $data['name'], 'slug' => $slug, 'status' => 'active', 'country' => strtoupper($data['country'] ?? 'DE'), 'locale' => $data['locale'] ?? 'ru', 'business_description' => $data['business_description'] ?? null]);
            $tenant->users()->attach($user, ['role' => 'owner']);
            $tenant->profile()->create(['contact_name' => $user->name, 'email' => $user->email]);
            $domain = $tenant->domains()->create(['domain' => $slug.'.'.config('tenancy.platform_domain'), 'type' => 'platform', 'is_primary' => true, 'status' => 'active', 'verified_at' => now(), 'ssl_status' => 'active', 'ssl_issued_at' => now()]);
            $tenant->update(['primary_domain_id' => $domain->id]);
            $complimentary = (bool) ($data['complimentary'] ?? false);
            $trial = ! $complimentary && $plan->trial_days > 0;
            $accessDays = $complimentary ? (int) ($data['complimentary_days'] ?? 14) : (int) $plan->trial_days;
            if ($complimentary) {
                $tenant->update(['manual_access_until' => now()->addDays($accessDays)]);
            }
            $tenant->subscriptions()->create([
                'plan_id' => $plan->id,
                'provider' => $trial ? 'lookdo' : 'stripe',
                'status' => $trial ? 'trialing' : 'incomplete',
                'complimentary' => false,
                'billing_cycle' => 'monthly',
                'currency' => $plan->currency,
                'unit_amount' => $plan->priceFor($plan->currency, 'monthly'),
                'started_at' => now(),
                'current_period_start' => $trial ? now() : null,
                'current_period_end' => $trial ? now()->addDays($accessDays) : null,
            ]);
            if (! empty($data['variation_id'])) {
                $variation = BusinessVariation::findOrFail($data['variation_id']);
                $template = RequestTemplate::where('code', $variation->template_code)->first();
                $tenant->businessProfile()->create(['category_id' => $variation->category_id, 'variation_id' => $variation->id, 'request_template_id' => $template?->id, 'original_description' => $data['business_description'] ?? null]);
            }

            return $tenant;
        });
        $audit->log('tenant.created_by_admin', $tenant, null, $tenant->toArray(), $tenant->id);

        return response()->json($tenant->load(['users', 'currentSubscription.plan', 'businessProfile.variation']), 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json($tenant->load([
            'users' => fn ($query) => $query->wherePivot('role', 'owner')->select('users.id', 'users.name', 'users.email', 'users.is_active', 'users.last_login_at'),
            'profile', 'domains', 'currentSubscription.plan', 'currentSubscription.payments', 'subscriptions.plan', 'subscriptions.payments',
            'businessProfile.category', 'businessProfile.variation', 'businessProfile.template',
        ]));
    }

    public function update(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $owner = $tenant->users()->wherePivot('role', 'owner')->firstOrFail();
        $data = $request->validate([
            'name' => 'nullable|string|max:160',
            'owner_name' => 'nullable|string|max:120',
            'owner_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($owner->id)],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'archived'])],
            'plan_id' => 'nullable|exists:plans,id', 'discount_percent' => 'nullable|integer|min:0|max:100',
        ]);
        $before = ['tenant' => $tenant->load('currentSubscription')->toArray(), 'owner' => $owner->toArray()];
        DB::transaction(function () use ($tenant, $owner, $data) {
            $tenant->update(array_filter(['name' => $data['name'] ?? null, 'status' => $data['status'] ?? null], fn ($value) => $value !== null));
            $owner->update(array_filter(['name' => $data['owner_name'] ?? null, 'email' => $data['owner_email'] ?? null], fn ($value) => $value !== null));
            if (isset($data['owner_name']) || isset($data['owner_email'])) {
                $tenant->profile()->updateOrCreate([], ['contact_name' => $data['owner_name'] ?? $owner->name, 'email' => $data['owner_email'] ?? $owner->email]);
            }
            if (isset($data['plan_id'])) {
                $sub = $tenant->currentSubscription;
                $payload = ['plan_id' => $data['plan_id'], 'discount_percent' => $data['discount_percent'] ?? ($sub?->discount_percent ?? 0)];
                $sub ? $sub->update($payload) : $tenant->subscriptions()->create($payload);
            }
        });
        $audit->log('tenant.updated', $tenant, $before, ['tenant' => $tenant->fresh('currentSubscription')->toArray(), 'owner' => $owner->fresh()->toArray()], $tenant->id);

        return response()->json($tenant->fresh(['currentSubscription.plan']));
    }

    public function grantAccess(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['days' => 'required|integer|min:1|max:3650']);
        $current = $tenant->currentSubscription()->with('plan')->firstOrFail();
        $before = $tenant->toArray();
        $base = $tenant->manual_access_until?->isFuture()
            ? $tenant->manual_access_until->copy()
            : now();
        $tenant->update(['manual_access_until' => $base->addDays((int) $data['days'])]);
        $tenant = $tenant->fresh(['currentSubscription.plan']);

        $audit->log('tenant.access.granted', $tenant, $before, $tenant->toArray(), $tenant->id);

        return response()->json([
            'subscription' => $current->fresh('plan'),
            'tenant' => $tenant,
        ]);
    }

    public function setOverride(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|max:100', 'value' => 'nullable|string|max:10000']);
        DB::table('tenant_entitlement_overrides')->updateOrInsert(['tenant_id' => $tenant->id, 'key' => $data['key']], ['value' => $data['value'], 'created_at' => now(), 'updated_at' => now()]);
        $audit->log('tenant.entitlement.updated', $tenant, null, $data, $tenant->id);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $hasLiveProviderSubscription = $tenant->subscriptions()
            ->whereNotNull('provider_subscription_id')
            ->whereNotIn('status', ['canceled', 'superseded'])
            ->exists();

        if ($hasLiveProviderSubscription) {
            throw ValidationException::withMessages([
                'tenant' => 'Das aktive Stripe-Abonnement muss vor dem Loeschen des Kunden beendet werden.',
            ]);
        }

        $tenantId = $tenant->id;
        $before = $tenant->load([
            'users' => fn ($query) => $query->wherePivot('role', 'owner'),
            'domains', 'subscriptions', 'profile',
        ])->toArray();
        $ownerIds = $tenant->users()->wherePivot('role', 'owner')->pluck('users.id');

        DB::transaction(function () use ($audit, $before, $ownerIds, $tenant): void {
            $audit->log('tenant.deleted', null, $before, ['deleted' => true]);
            $tenant->delete();
            User::query()->whereIn('id', $ownerIds)->where('is_super_admin', false)->get()
                ->each(function (User $owner): void {
                    if (! $owner->tenants()->exists()) {
                        $owner->delete();
                    }
                });
        });

        foreach (['tenant-social', 'tenant-app', 'tenant-logo', 'tenant-logos', 'tenant-portfolio'] as $directory) {
            Storage::disk('public')->deleteDirectory($directory.'/'.$tenantId);
        }

        return response()->json(['deleted' => true]);
    }

    public function updateOwner(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $owner = $this->owner($tenant);
        $data = $request->validate(['is_active' => 'required|boolean']);
        $before = $owner->toArray();
        $owner->update($data);
        $audit->log('tenant.owner.status.updated', $owner, $before, $owner->toArray(), $tenant->id);

        return response()->json($owner);
    }

    public function sendOwnerPasswordReset(Tenant $tenant, AuditService $audit): JsonResponse
    {
        $owner = $this->owner($tenant);
        $status = PasswordBroker::sendResetLink(['email' => $owner->email]);
        $audit->log('tenant.owner.password_reset.requested', $owner, null, null, $tenant->id);

        return response()->json(['status' => $status]);
    }

    private function owner(Tenant $tenant): User
    {
        return $tenant->users()->wherePivot('role', 'owner')->firstOrFail();
    }

    private function ensureDomainBelongsToTenant(Tenant $tenant, TenantDomain $domain): void
    {
        abort_unless($domain->tenant_id === $tenant->id, 404);
    }

    public function verifyDomain(Tenant $tenant, TenantDomain $domain, DomainService $service, AuditService $audit): JsonResponse
    {
        $this->ensureDomainBelongsToTenant($tenant, $domain);
        $before = $domain->toArray();
        $domain = $service->verify($domain);
        $audit->log('domain.admin.verified', $domain, $before, $domain->toArray(), $tenant->id);

        return response()->json($domain);
    }

    public function activateDomain(Tenant $tenant, TenantDomain $domain, AuditService $audit): JsonResponse
    {
        $this->ensureDomainBelongsToTenant($tenant, $domain);
        abort_unless(in_array($domain->status, ['ssl_pending', 'verifying'], true), 422);
        $before = $domain->toArray();
        DB::transaction(function () use ($domain, $tenant) {
            $tenant->domains()->update(['is_primary' => false]);
            $domain->update(['status' => 'active', 'ssl_status' => 'active', 'ssl_issued_at' => now(), 'is_primary' => true]);
            $tenant->update(['primary_domain_id' => $domain->id]);
        });
        $audit->log('domain.activated', $domain, $before, $domain->toArray(), $tenant->id);

        return response()->json($domain->fresh());
    }

    public function disableDomain(Tenant $tenant, TenantDomain $domain, DomainService $service, AuditService $audit): JsonResponse
    {
        $this->ensureDomainBelongsToTenant($tenant, $domain);
        abort_if($domain->type === 'platform', 422, 'A platform domain cannot be disabled.');
        $before = $domain->toArray();
        $domain = $service->disable($domain);
        $audit->log('domain.disabled', $domain, $before, $domain->toArray(), $tenant->id);

        return response()->json($domain->fresh());
    }

    public function deleteDomain(Tenant $tenant, TenantDomain $domain, DomainService $service, AuditService $audit): JsonResponse
    {
        $this->ensureDomainBelongsToTenant($tenant, $domain);
        abort_if($domain->type === 'platform' || $domain->status === 'active', 422, 'Disable the custom domain before deleting it.');
        $before = $domain->toArray();
        $service->remove($domain);
        $audit->log('domain.deleted_by_admin', null, $before, null, $tenant->id);

        return response()->json(['ok' => true]);
    }

    public function impersonate(Request $request, Tenant $tenant, AuditService $audit): JsonResponse
    {
        $owner = $this->owner($tenant);
        $request->session()->put('impersonator_id', $request->user()->id);
        $audit->log('tenant.impersonation.started', $tenant, null, ['user_id' => $owner->id], $tenant->id);
        Auth::login($owner);
        $request->session()->regenerate();

        return response()->json(['ok' => true, 'tenant_id' => $tenant->id]);
    }

    private function uniqueTenantSlug(string $value): string
    {
        $base = substr(trim(Str::slug(Str::ascii($value)) ?: 'business', '-'), 0, 50);
        if (in_array($base, config('tenancy.reserved_slugs'), true)) {
            $base .= '-business';
        }
        $slug = $base;
        $counter = 2;
        while (Tenant::where('slug', $slug)->exists() || in_array($slug, config('tenancy.reserved_slugs'), true)) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    private function perPage(Request $request): int
    {
        return max(10, min(100, $request->integer('per_page', 25)));
    }

    private function sortDirection(Request $request): string
    {
        return $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';
    }

    private function sortColumn(Request $request, array $allowed): string
    {
        $requested = $request->string('sort')->toString();

        return in_array($requested, $allowed, true) ? $requested : 'created_at';
    }
}
